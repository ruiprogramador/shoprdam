<?php

namespace App\Domain\Payouts\DTOs;

/**
 * A provider adapter's normalized answer to "here is the transfer for this
 * attempt" — whether freshly created or retrieved. Mirrors
 * App\Domain\Payments\DTOs\ProviderPaymentResult. Amount is kept as the same
 * decimal(2) string convention the Wallet ledger uses (bcmath), not
 * MinorUnits — a payout never needs to round-trip through a card network's
 * minor-units convention, so converting would only introduce a lossy step
 * this domain doesn't need.
 *
 * `correlationId` is whatever the provider was told to echo back — for
 * every adapter in this domain today, the Payout's own id as a string.
 */
final readonly class ProviderTransferResult
{
    public function __construct(
        public string $providerReference,
        public string $amount,
        public string $currency,
        public ?string $correlationId,
    ) {}
}
