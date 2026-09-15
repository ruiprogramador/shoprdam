<?php

namespace App\Payouts\Manual;

use App\Domain\Payouts\Contracts\PayoutProviderContract;
use App\Domain\Payouts\DTOs\ProviderTransferResult;
use App\Domain\Payouts\Enums\FailureClass;
use App\Domain\Payouts\Models\PayoutAttempt;
use Throwable;

/**
 * Implements PayoutProviderContract without ever calling out to a real bank
 * API — an operator executes the SEPA transfer outside this application,
 * then reports the outcome through an audited admin action (see
 * App\Http\Controllers\Admin\PayoutRecoveryController and
 * App\Domain\Payouts\Services\PayoutEventProcessor). Nothing here ever
 * touches the Wallet or fabricates a settlement — this class exists solely
 * to give PayoutService a technical `provider_reference` to correlate an
 * attempt by, exactly as any future automatic provider would.
 *
 * `createTransfer()` never fails and never blocks on I/O — there is no
 * remote system to call. It deterministically mints a reference from the
 * attempt's own idempotency_key, so calling it again for the same attempt
 * (reconciliation retrying a stale `pending` row) always returns the exact
 * same reference — satisfying the same idempotency contract a real HTTP
 * provider must uphold. The REAL evidence a transfer happened is never this
 * reference — see PayoutAttempt.external_transfer_reference, populated only
 * by PayoutEventProcessor::applySucceeded() from an operator's own
 * confirmation, never by this class.
 */
class ManualPayoutProvider implements PayoutProviderContract
{
    public function name(): string
    {
        return 'manual';
    }

    public function createTransfer(PayoutAttempt $attempt): ProviderTransferResult
    {
        return new ProviderTransferResult(
            providerReference: "manual-{$attempt->idempotency_key}",
            amount: $attempt->payout->amount,
            currency: $attempt->payout->currency->code,
            correlationId: (string) $attempt->payout_id,
        );
    }

    /**
     * Never actually invoked in production — createTransfer() never throws.
     * Present only to satisfy the contract; classifies everything as
     * non-retryable since there is no transient/network failure mode for a
     * call that never leaves this process.
     */
    public function classifyFailure(Throwable $e): FailureClass
    {
        return FailureClass::NonRetryable;
    }
}
