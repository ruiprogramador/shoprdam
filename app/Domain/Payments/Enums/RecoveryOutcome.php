<?php

namespace App\Domain\Payments\Enums;

/**
 * What App\Domain\Payments\Services\PaymentAttemptRecoveryService::recover()
 * actually did with one PaymentAttempt — shared by both of its callers,
 * App\Console\Commands\ReconcileOrphanedPaymentAttempts (bulk, scheduled)
 * and App\Http\Controllers\Admin\PaymentRecoveryController (single,
 * human-triggered), so a manual retry can never diverge from what automatic
 * reconciliation would have done to the same row. Each caller renders its
 * own log/print/audit text from this; neither guesses at what happened.
 */
enum RecoveryOutcome
{
    /** Another worker already holds this attempt's lease, or already resolved it — nothing was done. */
    case Skipped;

    /** finalizeAttempt() succeeded — the attempt is now claimed (or further, if it settled synchronously). */
    case Recovered;

    /** Sent straight to `needs_attention` for exceeding --max-age, without ever calling the provider. */
    case AgeExceeded;

    /** Sent to `needs_attention`: recovery attempts exhausted, or the provider's failure was non-retryable. */
    case NeedsAttention;

    /** Failed, but the failure is retryable and attempts remain — still `pending` for a later run. */
    case RetryPending;

    /** The attempt had already left `pending` (a real transition committed) before/while this ran — left untouched. */
    case AlreadyProgressed;
}
