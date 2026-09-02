<?php

namespace App\Payments\EasyPay;

use App\Payments\EasyPay\Exceptions\EasyPayConnectionException;
use App\Payments\EasyPay\Exceptions\EasyPayRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A thin wrapper around EasyPay's REST API (no official PHP SDK exists, per
 * docs.easypay.pt — unlike Stripe's StripeClient). Every EasyPay-specific
 * detail (base URL, AccountId/ApiKey headers, Idempotency-Key header) stays
 * here; callers get back plain decoded JSON arrays and typed exceptions.
 * Used by both EasyPayPaymentProvider (create/retrieve a payment) and
 * EasyPayWebhookController (the mandatory "call back the API to verify a
 * notification" step EasyPay documents in place of signature verification —
 * see docs.easypay.pt/docs/guides/webhooks).
 */
class EasyPayClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $accountId,
        private readonly string $apiKey,
    ) {}

    /**
     * POST /2.0/single — creates (or, replayed under the same Idempotency-Key,
     * returns) a single payment. `$idempotencyKey` mirrors the attempt's own
     * deterministic key, capped at EasyPay's documented 50-character limit
     * by the caller (see PaymentService::createDurableAttempt()'s
     * `payment-{id}-attempt-{id}` format, which comfortably fits).
     */
    public function createSinglePayment(array $params, string $idempotencyKey): array
    {
        return $this->request('post', 'single', $params, $idempotencyKey);
    }

    /** GET /2.0/single/{id} — the canonical, authoritative payment state. */
    public function retrieveSinglePayment(string $id): array
    {
        return $this->request('get', "single/{$id}");
    }

    /**
     * GET /2.0/refund/{id} — the canonical refund state, used only by
     * EasyPayWebhookController to verify a refund-type notification before
     * translating it. EasyPay's public docs don't publish this response
     * shape explicitly; assumed to follow the same {id, status, value,
     * payment_id} shape their documented GET /2.0/capture/{id} response
     * uses (EasyPay's REST resources are consistently shaped) — verify
     * against the sandbox before relying on this for a real refund.
     */
    public function retrieveRefund(string $id): array
    {
        return $this->request('get', "refund/{$id}");
    }

    private function request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $headers = [
            'AccountId' => $this->accountId,
            'ApiKey' => $this->apiKey,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $response = Http::baseUrl($this->baseUrl)->withHeaders($headers)->{$method}($path, $params);
        } catch (ConnectionException $e) {
            throw new EasyPayConnectionException("Simulated network failure reaching EasyPay: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            throw new EasyPayRequestException(
                status: $response->status(),
                body: (array) $response->json(),
                message: "EasyPay API error ({$response->status()}) on {$method} {$path}: {$response->body()}",
            );
        }

        return (array) $response->json();
    }
}
