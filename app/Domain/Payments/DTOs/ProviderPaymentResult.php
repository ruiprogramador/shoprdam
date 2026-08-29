<?php

namespace App\Domain\Payments\DTOs;

/**
 * A provider adapter's normalized answer to "here is the remote payment for
 * this attempt" — whether freshly created or retrieved. Every provider
 * adapter (App\Payments\Stripe\StripePaymentProvider today) maps its own
 * native response shape (a Stripe PaymentIntent, a PayPal Order, ...) into
 * this small, fixed set of fields rather than the domain trying to
 * understand every provider's native object.
 *
 * `correlationId` is whatever value the provider was told to echo back that
 * ties the remote payment to *this* attempt — Stripe's `metadata.order_id`
 * today, generalized to "the correlation value", never assumed to be an
 * Order id for other providers.
 */
final readonly class ProviderPaymentResult
{
    public function __construct(
        public string $providerReference,
        public int $amountMinorUnits,
        public string $currency,
        public string $providerStatus,
        public ?string $correlationId,
    ) {}
}
