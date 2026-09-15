<?php

namespace App\Policies;

use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Models\Admin;

/**
 * Mirrors App\Policies\PaymentRecoveryPolicy: there is only one Admin type
 * (no role/permission column), so "authorized" means "an authenticated
 * admin". What actually gates confirmation is never the admin's identity —
 * it's whether $attempt is in a state a manual confirmation can safely
 * apply to at all:
 *
 * - the attempt must already have a `provider_reference` (Claimed or
 *   NeedsAttention with one) — confirming an attempt still `Pending` (never
 *   claimed) makes no sense: there is nothing yet to confirm the outcome
 *   of. A `retry`/claim action (see PayoutAttemptRecoveryService) is the
 *   correct tool for that state, not this one.
 * - the attempt must not already be terminal (Succeeded/Failed) — a
 *   terminal attempt's outcome is already recorded; PayoutEventProcessor's
 *   own CAS makes a repeat submission a safe no-op regardless, but this
 *   policy fails closed earlier, before ever building an outcome DTO.
 */
class PayoutRecoveryPolicy
{
    /**
     * Mirrors PaymentRecoveryPolicy::STALE_AFTER_MINUTES exactly — a manual
     * retry must never be looser than what
     * App\Console\Commands\ReconcileOrphanedPayoutAttempts's own
     * `--stale-after` default would consider eligible.
     */
    public const STALE_AFTER_MINUTES = 5;

    public function view(Admin $admin, PayoutAttempt $attempt): bool
    {
        return true;
    }

    public function confirm(Admin $admin, PayoutAttempt $attempt): bool
    {
        return $attempt->provider_reference !== null
            && $attempt->status->acceptsOutcome();
    }

    /** Whether a stuck `pending` attempt is at least as stale as automatic reconciliation itself requires. */
    public function retry(Admin $admin, PayoutAttempt $attempt): bool
    {
        return $attempt->status === PayoutAttemptStatus::Pending
            && $attempt->created_at->lte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
