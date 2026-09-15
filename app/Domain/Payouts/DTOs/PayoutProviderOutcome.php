<?php

namespace App\Domain\Payouts\DTOs;

use App\Domain\Payouts\Enums\PayoutOutcomeType;
use Illuminate\Support\Carbon;

/**
 * The provider-neutral outcome App\Domain\Payouts\Services\PayoutEventProcessor::apply()
 * consumes — the single entry point every source of truth about a transfer
 * ends at, whether that's an operator's manual confirmation today (see
 * App\Http\Controllers\Admin\PayoutRecoveryController) or a future
 * provider's own webhook/poll translator. Mirrors
 * App\Domain\Payments\DTOs\ProviderEventOutcome.
 *
 * `externalTransferReference` is the REAL bank/SEPA reference proving a
 * transfer happened — required for a Succeeded outcome, never to be
 * confused with `providerReference` (the attempt's technical identity; see
 * PayoutAttempt's own docblock). Optional for Failed (a rejected transfer
 * may have no real reference).
 */
final readonly class PayoutProviderOutcome
{
    public function __construct(
        public string $provider,
        public string $providerReference,
        public PayoutOutcomeType $type,
        public string $amount,
        public string $currency,
        public ?string $correlationId,
        public ?string $externalTransferReference = null,
        public ?string $failureReason = null,
        public ?Carbon $executedAt = null,
    ) {}
}
