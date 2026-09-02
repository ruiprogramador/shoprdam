<?php

namespace App\Payments\EasyPay;

use App\Domain\Payments\Contracts\PaymentProviderContract;
use App\Domain\Payments\Contracts\SupportsCanonicalRetrieval;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\MinorUnits;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Payments\EasyPay\Exceptions\EasyPayConnectionException;
use App\Payments\EasyPay\Exceptions\EasyPayRequestException;
use InvalidArgumentException;
use Throwable;

/**
 * The EasyPay implementation of the payments domain's provider contract —
 * the second real provider, proving App\Domain\Payments generalizes beyond
 * Stripe. Everything EasyPay-specific (its REST shape, its `mb`/`mbway`
 * method codes, its error codes) stays behind this class and
 * EasyPayEventTranslator; nothing past App\Domain\Payments\Services\PaymentService
 * ever sees an EasyPay response shape. See docs/wallet/integrations.md.
 *
 * Unlike Stripe's single `card` method, EasyPay is one provider serving two
 * distinct `method`s this domain already models as such — `mbway` and
 * `multibanco` (see PaymentAttempt.method) — never as separate providers;
 * see docs/wallet/integrations.md ("Payment vs. PaymentAttempt vs. provider
 * vs. method").
 *
 * EasyPay's `POST /2.0/single` with `type: sale` performs an immediate
 * authorize+capture in one step (mirroring Stripe's auto-confirmed
 * PaymentIntent) — the authorize/capture split EasyPay's API also supports
 * is deliberately unused here, keeping this adapter's lifecycle a direct,
 * one-call match for what PaymentProviderContract::createOrGetPayment()
 * needs. Multibanco doesn't support that split at all (sale-only).
 */
class EasyPayPaymentProvider implements PaymentProviderContract, SupportsCanonicalRetrieval
{
    private EasyPayClient $client;

    public function __construct()
    {
        $this->client = new EasyPayClient(
            config('services.easypay.base_url'),
            config('services.easypay.account_id'),
            config('services.easypay.api_key'),
        );
    }

    public function name(): string
    {
        return 'easypay';
    }

    public function createOrGetPayment(PaymentAttempt $attempt): ProviderPaymentResult
    {
        $order = $attempt->payment->order;

        $body = $this->client->createSinglePayment(
            [
                'type' => 'sale',
                'value' => $order->amount,
                'currency' => strtoupper($order->currency->code),
                // The merchant correlation field EasyPay echoes back on the
                // resource (≤ 50 chars) — the direct counterpart to
                // Stripe's `metadata.order_id`.
                'key' => (string) $order->id,
                'method' => $this->methodCode($attempt->method),
            ],
            $attempt->idempotency_key,
        );

        return $this->toResult($body);
    }

    public function retrieveByReference(string $providerReference): ProviderPaymentResult
    {
        return $this->toResult($this->client->retrieveSinglePayment($providerReference));
    }

    /**
     * A definitive EasyPay rejection (bad credentials, a malformed request,
     * a resource that doesn't exist, a validation error) fails identically
     * on every retry. Per EasyPay's documented error codes
     * (docs.easypay.pt/docs/error-handling): 409 (the original request may
     * still be in transit), 429 (rate limit), and 5xx are Retryable —
     * safe to repeat under the same Idempotency-Key, exactly like Stripe's
     * RateLimitException/ApiConnectionException handling. 400/403/404/422
     * are NonRetryable.
     */
    public function classifyFailure(Throwable $e): FailureClass
    {
        return match (true) {
            $e instanceof EasyPayConnectionException => FailureClass::Retryable,
            $e instanceof EasyPayRequestException => in_array($e->status, [409, 429, 500, 502, 503], true)
                ? FailureClass::Retryable
                : FailureClass::NonRetryable,
            default => FailureClass::Retryable,
        };
    }

    /**
     * `PaymentAttempt.method` values this domain uses ('mbway',
     * 'multibanco') vs. the method codes EasyPay's own API expects ('mbway',
     * 'mb') — never conflated with `provider`, which stays `easypay` for
     * both. See the class docblock.
     */
    private function methodCode(string $method): string
    {
        return match ($method) {
            'mbway' => 'mbway',
            'multibanco' => 'mb',
            default => throw new InvalidArgumentException("EasyPay does not support payment method '{$method}'."),
        };
    }

    private function toResult(array $body): ProviderPaymentResult
    {
        return new ProviderPaymentResult(
            providerReference: (string) $body['id'],
            amountMinorUnits: MinorUnits::fromDecimal((string) $body['value']),
            currency: strtolower((string) $body['currency']),
            providerStatus: (string) $body['status'],
            correlationId: isset($body['key']) ? (string) $body['key'] : null,
        );
    }
}
