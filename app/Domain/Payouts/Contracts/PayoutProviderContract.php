<?php

namespace App\Domain\Payouts\Contracts;

use App\Domain\Payouts\DTOs\ProviderTransferResult;
use App\Domain\Payouts\Enums\FailureClass;
use App\Domain\Payouts\Models\PayoutAttempt;
use Throwable;

/**
 * The minimum every payout provider adapter must implement — deliberately
 * mirrors App\Domain\Payments\Contracts\PaymentProviderContract's shape and
 * its reasoning. Kept intentionally small so App\Payouts\Manual\ManualPayoutProvider
 * (no HTTP, no automatic transfer — see its own docblock) and a future
 * automatic provider (Stripe Connect, a SEPA API) can implement the exact
 * same contract without App\Domain\Payouts\Services\PayoutService ever
 * needing an `if ($provider === 'manual')` branch anywhere.
 *
 * Polling a provider for its own canonical transfer status (needed for
 * reconciliation against an automatic provider, meaningless for a manual
 * one — a human can't be polled) is deliberately NOT part of this base
 * contract; add a capability-specific interface
 * (e.g. SupportsTransferPolling, mirroring SupportsCanonicalRetrieval on the
 * payments side) only once a real provider actually needs it.
 */
interface PayoutProviderContract
{
    /** The driver name this adapter is resolved under, e.g. 'manual'. */
    public function name(): string;

    /**
     * Idempotently create (or, on a retry under the same attempt, return
     * the existing) transfer for this attempt. Must be safe to call more
     * than once for the same PayoutAttempt under its own idempotency_key —
     * this is what a future reconciliation command relies on to recover a
     * crash between the provider responding and the local claim completing.
     *
     * Never moves money itself for a provider like Manual — see that
     * adapter's own docblock. What this returns is only ever a technical
     * correlation (PayoutAttempt.provider_reference), never proof of
     * execution — see PayoutAttempt's own docblock for that distinction.
     */
    public function createTransfer(PayoutAttempt $attempt): ProviderTransferResult;

    /**
     * Classify a failure from createTransfer() (or any other call this
     * adapter makes) as worth retrying or not. Mirrors
     * PaymentProviderContract::classifyFailure() exactly.
     */
    public function classifyFailure(Throwable $e): FailureClass;
}
