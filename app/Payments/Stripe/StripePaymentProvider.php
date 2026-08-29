<?php

namespace App\Payments\Stripe;

use App\Domain\Payments\Contracts\PaymentProviderContract;
use App\Domain\Payments\Contracts\SupportsCanonicalRetrieval;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\MinorUnits;
use App\Domain\Payments\Models\PaymentAttempt;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Throwable;

/**
 * The Stripe implementation of the payments domain's provider contract.
 * Everything Stripe-specific (the SDK, PaymentIntent, its exception
 * hierarchy) stays behind this class and StripeEventTranslator — nothing
 * past App\Domain\Payments\Services\PaymentService ever sees a Stripe SDK
 * type. See docs/wallet/integrations.md.
 */
class StripePaymentProvider implements PaymentProviderContract, SupportsCanonicalRetrieval
{
    private StripeClient $client;

    public function __construct()
    {
        $this->client = new StripeClient(config('services.stripe.secret'));
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function createOrGetPayment(PaymentAttempt $attempt): ProviderPaymentResult
    {
        $order = $attempt->payment->order;

        $paymentIntent = $this->client->paymentIntents->create(
            [
                'amount' => MinorUnits::fromDecimal($order->amount),
                'currency' => strtolower($order->currency->code),
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
            ],
            [
                'idempotency_key' => $attempt->idempotency_key,
            ],
        );

        return $this->toResult($paymentIntent);
    }

    public function retrieveByReference(string $providerReference): ProviderPaymentResult
    {
        return $this->toResult($this->client->paymentIntents->retrieve($providerReference));
    }

    /**
     * Not every failure deserves a retry, regardless of how many recovery
     * attempts are left. A definitive Stripe rejection (bad credentials, a
     * malformed request, a declined card, an idempotency key reused with
     * different params) will fail identically on every retry.
     *
     * RateLimitException is checked *before* InvalidRequestException, not
     * just alongside it: Stripe's SDK defines
     * `RateLimitException extends InvalidRequestException`, so if the
     * non-retryable arm were checked first, every 429 would match it via
     * that inherited type and never be classified as transient
     * backpressure at all.
     */
    public function classifyFailure(Throwable $e): FailureClass
    {
        return match (true) {
            $e instanceof RateLimitException,
            $e instanceof ApiConnectionException => FailureClass::Retryable,
            $e instanceof AuthenticationException,
            $e instanceof PermissionException,
            $e instanceof InvalidRequestException,
            $e instanceof CardException,
            $e instanceof IdempotencyException => FailureClass::NonRetryable,
            $e instanceof ApiErrorException => ($e->getHttpStatus() ?? 500) >= 500
                ? FailureClass::Retryable
                : FailureClass::NonRetryable,
            default => FailureClass::Retryable,
        };
    }

    private function toResult(PaymentIntent $paymentIntent): ProviderPaymentResult
    {
        return new ProviderPaymentResult(
            providerReference: $paymentIntent->id,
            amountMinorUnits: $paymentIntent->amount,
            currency: (string) $paymentIntent->currency,
            providerStatus: $paymentIntent->status,
            correlationId: $paymentIntent->metadata['order_id'] ?? null,
        );
    }
}
