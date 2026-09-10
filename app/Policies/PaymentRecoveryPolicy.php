<?php

namespace App\Policies;

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Models\Admin;

/**
 * There is only one Admin type in this application (see App\Models\Admin —
 * no role/permission column), so "authorized" here means "an authenticated
 * admin", the same model App\Policies\AdminKycPolicy already uses. What
 * actually gates the mutating action is never the admin's identity — it's
 * whether $attempt is in a state App\Http\Controllers\Admin\PaymentRecoveryController
 * (and, underneath it, App\Domain\Payments\Services\PaymentAttemptRecoveryService /
 * PaymentService::finalizeAttempt()) can safely act on at all:
 *
 * - a `pending` attempt may run recovery, but only once it's at least as
 *   stale as automatic reconciliation itself requires — see
 *   isStalePending(). A *fresh* pending attempt may still be waiting on its
 *   original provider call to come back; manually retrying it before
 *   App\Console\Commands\ReconcileOrphanedPaymentAttempts itself would ever
 *   touch it is exactly the "manual recovery looser than automatic
 *   reconciliation" this domain must not allow;
 * - ANY attempt with an established `provider_reference` — including a
 *   terminal, historical one (Succeeded or Failed) — may safely replay its
 *   pending provider events, since finalizeAttempt() never re-calls the
 *   provider once a reference exists and replay is a no-op when nothing is
 *   actually pending;
 * - a `needs_attention` (or any other) attempt with NO provider reference
 *   at all has neither path available and stays blocked — there is no
 *   provider evidence to retry or replay against, so this fails closed
 *   rather than guessing. See the controller's own docblock for why that's
 *   deliberate, not a gap.
 *
 * Lease *ownership* itself is never decided here — a `pending` attempt
 * passing isStalePending() only means retry() is worth *attempting*;
 * whether it actually proceeds (versus another worker already holding the
 * lease) is still resolved atomically, exclusively, by
 * PaymentAttemptRecoveryService's own conditional `UPDATE ... WHERE status
 * = 'pending'`. This policy answers "is it worth trying", never "is it safe
 * to mutate" — the service is the only thing that ever answers that.
 */
class PaymentRecoveryPolicy
{
    /**
     * Mirrors App\Console\Commands\ReconcileOrphanedPaymentAttempts's own
     * `--stale-after` default (5 minutes) exactly — deliberately NOT the
     * same value as `payments.health.stale_pending_minutes` (default 15),
     * which answers a different question ("has this been stale long enough
     * to alert on") than this one ("would automatic reconciliation itself
     * consider this attempt yet").
     */
    public const STALE_AFTER_MINUTES = 5;

    public function view(Admin $admin, PaymentAttempt $attempt): bool
    {
        return true;
    }

    /** Whether *some* safe recovery action exists for $attempt right now — the controller decides which. */
    public function retry(Admin $admin, PaymentAttempt $attempt): bool
    {
        return $this->isStalePending($attempt)
            || $attempt->provider_reference !== null;
    }

    /**
     * status=pending AND created_at <= stale cutoff — the same shape
     * App\Console\Commands\ReconcileOrphanedPaymentAttempts's own
     * eligibility query uses (`--stale-after` minutes back from now).
     * Server-side manual-recovery eligibility must never be looser than
     * automatic reconciliation's; this is the single place both this
     * policy's retry() and the controller's own defense-in-depth re-check
     * derive that from, so the two can't drift apart.
     */
    public function isStalePending(PaymentAttempt $attempt): bool
    {
        return $attempt->status === PaymentAttemptStatus::Pending
            && $attempt->created_at->lte(now()->subMinutes(self::STALE_AFTER_MINUTES));
    }
}
