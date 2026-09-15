<?php

namespace App\Domain\Payouts\Enums;

/**
 * What App\Domain\Payouts\Services\PayoutAttemptRecoveryService::recover()
 * actually did with one PayoutAttempt — mirrors
 * App\Domain\Payments\Enums\RecoveryOutcome exactly, shared by both its
 * callers (App\Console\Commands\ReconcileOrphanedPayoutAttempts and
 * App\Http\Controllers\Admin\PayoutRecoveryController's retry action) so a
 * manual retry can never diverge from what automatic reconciliation would
 * have done to the same row.
 */
enum RecoveryOutcome
{
    /** Another worker already holds this attempt's lease, or already resolved it — nothing was done. */
    case Skipped;

    /** finalizeAttempt() succeeded — the attempt is now claimed. */
    case Recovered;

    /** Sent straight to needs_attention for exceeding --max-age, without ever calling the provider. */
    case AgeExceeded;

    /** Sent to needs_attention: recovery attempts exhausted, or the provider's failure was non-retryable. */
    case NeedsAttention;

    /** Failed, but the failure is retryable and attempts remain — still `pending` for a later run. */
    case RetryPending;

    /** The attempt had already left `pending` (a real transition committed) before/while this ran — left untouched. */
    case AlreadyProgressed;
}
