<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\PaymentProviderContract;
use App\Domain\Payments\DTOs\RecoveryResult;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\RecoveryOutcome;
use App\Domain\Payments\Exceptions\PaymentAttemptMismatchException;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\PaymentProviderManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single per-attempt recovery algorithm — lease acquisition, the
 * provider retry via PaymentService::finalizeAttempt(), and failure
 * bookkeeping — shared by App\Console\Commands\ReconcileOrphanedPaymentAttempts
 * (bulk, scheduled, every attempt currently stale) and
 * App\Http\Controllers\Admin\PaymentRecoveryController (one attempt, an
 * authorized operator explicitly asking for it right now). Extracted from
 * that command unchanged — see its own history for why each guard exists —
 * so a manual retry can never quietly diverge from what automatic
 * reconciliation would have done to the same row: there is exactly one
 * place that decides whether a new attempt at recovery is safe to start.
 *
 * Every state change here — acquiring the lease, sending an attempt to
 * `needs_attention`, or recording a recovery failure — is a single
 * conditional `UPDATE ... WHERE status = 'pending'`, never a plain
 * `$attempt->update(...)`: two callers racing on the same attempt (the
 * scheduler and an operator clicking retry at the same moment) can't both
 * call the provider for it, and neither can ever clobber a valid transition
 * that happened after it last read the row — the database's current state,
 * not a possibly-stale in-memory copy, decides which UPDATE's WHERE still
 * matches. This is what makes "manual recovery must use the same
 * concurrency protections as automatic recovery" true by construction
 * rather than by convention: both callers are the same code path.
 *
 * Deliberately does no logging/printing/auditing itself — see
 * RecoveryOutcome and RecoveryResult. Each caller renders its own
 * message/audit-trail entry from the result, since a CLI run and a single
 * authorized admin action have different, equally legitimate, needs for
 * what to say about the same outcome.
 */
class PaymentAttemptRecoveryService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentProviderManager $providers,
    ) {}

    /**
     * Attempts to recover exactly one attempt. Safe to call for an attempt
     * that turns out to be ineligible (already leased by another
     * caller, already resolved) — returns RecoveryOutcome::Skipped rather
     * than erroring, the same "another worker got there first" outcome the
     * bulk command has always treated as a normal, silent no-op.
     */
    public function recover(PaymentAttempt $attempt, int $maxAttempts, int $maxAge, int $leaseTimeout): RecoveryResult
    {
        $ageMinutes = $attempt->created_at->diffInMinutes(now());

        if ($ageMinutes >= $maxAge) {
            if (! $this->markNeedsAttention($attempt)) {
                return new RecoveryResult(RecoveryOutcome::Skipped);
            }

            return new RecoveryResult(RecoveryOutcome::AgeExceeded, ageMinutes: $ageMinutes, maxAge: $maxAge);
        }

        if (! $this->acquireLease($attempt, $leaseTimeout)) {
            return new RecoveryResult(RecoveryOutcome::Skipped);
        }

        try {
            $this->paymentService->finalizeAttempt($attempt);

            return new RecoveryResult(RecoveryOutcome::Recovered);
        } catch (Throwable $e) {
            $retryable = $this->isRetryable($e, $this->providers->driver($attempt->provider));
            $recoveryAttempts = $attempt->recovery_attempts + 1;
            $exhausted = $recoveryAttempts >= $maxAttempts;

            $updated = $this->recordRecoveryFailure($attempt, $e, (! $retryable || $exhausted)
                ? ['status' => PaymentAttemptStatus::NeedsAttention, 'locked_until' => null]
                // Releases the lease this call took via acquireLease() above,
                // so a later caller's own eligibility check can pick this
                // attempt up again (created_at, which that check filters
                // on, is unchanged).
                : ['locked_until' => null]);

            if (! $updated) {
                // finalizeAttempt() actually committed its claim (status is
                // no longer `pending`) before this exception was thrown —
                // e.g. its post-claim replayUnmatchedEvents() step failed.
                // The attempt already moved on to a real, valid state;
                // overwriting it here (and recording a misleading
                // recovery_attempts/last_recovery_error against an attempt
                // that didn't actually fail to recover) would silently
                // clobber that transition. Leave it untouched.
                return new RecoveryResult(RecoveryOutcome::AlreadyProgressed, exception: $e);
            }

            if (! $retryable || $exhausted) {
                return new RecoveryResult(
                    RecoveryOutcome::NeedsAttention,
                    exception: $e,
                    retryable: $retryable,
                    recoveryAttempts: $recoveryAttempts,
                    maxAttempts: $maxAttempts,
                );
            }

            return new RecoveryResult(
                RecoveryOutcome::RetryPending,
                exception: $e,
                retryable: $retryable,
                recoveryAttempts: $recoveryAttempts,
                maxAttempts: $maxAttempts,
            );
        }
    }

    /**
     * Applies this recovery failure's bookkeeping and status/lease change in
     * one atomic conditional UPDATE, guarded by the same `WHERE status =
     * 'pending'` invariant as acquireLease()/markNeedsAttention() (see the
     * class docblock) — never a plain `$attempt->update(...)`. Returns
     * whether this call actually matched the row; false means the attempt's
     * real status had already moved past `pending` by the time this ran, and
     * nothing was written.
     *
     * @param  array<string, mixed>  $statusChanges  extra columns beyond the shared recovery bookkeeping ones
     */
    private function recordRecoveryFailure(PaymentAttempt $attempt, Throwable $e, array $statusChanges): bool
    {
        $affected = PaymentAttempt::where('id', $attempt->id)
            ->where('status', PaymentAttemptStatus::Pending)
            ->update([
                ...$statusChanges,
                'recovery_attempts' => DB::raw('recovery_attempts + 1'),
                'last_attempted_at' => now(),
                'last_recovery_error' => $e->getMessage(),
            ]);

        return $affected === 1;
    }

    /**
     * Atomically acquires (or renews, if already expired) the reconciliation
     * lease on one attempt, re-checking its eligibility against the
     * database's *current* state rather than the possibly-stale copy the
     * caller read earlier. Returns whether this call was the one that won it.
     */
    private function acquireLease(PaymentAttempt $attempt, int $leaseTimeout): bool
    {
        $affected = PaymentAttempt::where('id', $attempt->id)
            ->where('status', PaymentAttemptStatus::Pending)
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->update(['locked_until' => now()->addMinutes($leaseTimeout), 'last_attempted_at' => now()]);

        if ($affected === 1) {
            $attempt->locked_until = now()->addMinutes($leaseTimeout);

            return true;
        }

        return false;
    }

    private function markNeedsAttention(PaymentAttempt $attempt): bool
    {
        $affected = PaymentAttempt::where('id', $attempt->id)
            ->where('status', PaymentAttemptStatus::Pending)
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->update(['status' => PaymentAttemptStatus::NeedsAttention, 'locked_until' => null]);

        return $affected === 1;
    }

    /**
     * A definitive rejection (bad credentials, a malformed request, a
     * declined card, a mismatched provider payment) will fail identically
     * on every retry — burning through `$maxAttempts` against it only
     * delays `needs_attention` and wastes provider calls.
     * PaymentAttemptMismatchException is a domain-level, provider-agnostic
     * concern (see PaymentService), checked before ever asking the
     * provider; everything else is delegated to that provider's own
     * classifyFailure().
     */
    private function isRetryable(Throwable $e, PaymentProviderContract $provider): bool
    {
        if ($e instanceof PaymentAttemptMismatchException) {
            return false;
        }

        return $provider->classifyFailure($e) === FailureClass::Retryable;
    }
}
