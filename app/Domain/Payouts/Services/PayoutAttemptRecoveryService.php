<?php

namespace App\Domain\Payouts\Services;

use App\Domain\Payouts\Contracts\PayoutProviderContract;
use App\Domain\Payouts\DTOs\RecoveryResult;
use App\Domain\Payouts\Enums\FailureClass;
use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Enums\RecoveryOutcome;
use App\Domain\Payouts\Exceptions\PayoutAttemptMismatchException;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\PayoutProviderManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single per-attempt recovery algorithm — lease acquisition, the
 * provider retry via PayoutService::finalizeAttempt(), and failure
 * bookkeeping — mirrors App\Domain\Payments\Services\PaymentAttemptRecoveryService
 * exactly, shared by App\Console\Commands\ReconcileOrphanedPayoutAttempts
 * (bulk, scheduled) and App\Http\Controllers\Admin\PayoutRecoveryController's
 * retry action (one attempt, an authorized operator explicitly asking for it
 * right now).
 *
 * Every state change here is a single conditional `UPDATE ... WHERE status =
 * 'pending'`, never a plain `$attempt->update(...)` — the same reasoning as
 * the payments side: two callers racing on the same attempt can't both
 * proceed, and neither can ever clobber a valid transition that happened
 * after it last read the row.
 *
 * Recovering a Payout attempt never touches the Wallet — it only ever
 * (re)claims a provider_reference. Settlement/failure stays the exclusive
 * responsibility of PayoutEventProcessor::apply(), reached only by real
 * evidence (a manual confirmation, or a future provider webhook/poll) —
 * never by this service running out of retries, which only ever produces
 * NeedsAttention.
 */
class PayoutAttemptRecoveryService
{
    public function __construct(
        private readonly PayoutService $payoutService,
        private readonly PayoutProviderManager $providers,
    ) {}

    /**
     * Attempts to recover exactly one attempt. Safe to call for an attempt
     * that turns out to be ineligible (already leased, already resolved) —
     * returns RecoveryOutcome::Skipped rather than erroring.
     */
    public function recover(PayoutAttempt $attempt, int $maxAttempts, int $maxAge, int $leaseTimeout): RecoveryResult
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
            $this->payoutService->finalizeAttempt($attempt);

            return new RecoveryResult(RecoveryOutcome::Recovered);
        } catch (Throwable $e) {
            $retryable = $this->isRetryable($e, $this->providers->driver($attempt->provider));
            $recoveryAttempts = $attempt->recovery_attempts + 1;
            $exhausted = $recoveryAttempts >= $maxAttempts;

            $updated = $this->recordRecoveryFailure($attempt, $e, (! $retryable || $exhausted)
                ? ['status' => PayoutAttemptStatus::NeedsAttention, 'locked_until' => null]
                : ['locked_until' => null]);

            if (! $updated) {
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
     * @param  array<string, mixed>  $statusChanges
     */
    private function recordRecoveryFailure(PayoutAttempt $attempt, Throwable $e, array $statusChanges): bool
    {
        $affected = PayoutAttempt::where('id', $attempt->id)
            ->where('status', PayoutAttemptStatus::Pending)
            ->update([
                ...$statusChanges,
                'recovery_attempts' => DB::raw('recovery_attempts + 1'),
                'last_attempted_at' => now(),
                'last_recovery_error' => $e->getMessage(),
            ]);

        return $affected === 1;
    }

    private function acquireLease(PayoutAttempt $attempt, int $leaseTimeout): bool
    {
        $affected = PayoutAttempt::where('id', $attempt->id)
            ->where('status', PayoutAttemptStatus::Pending)
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

    private function markNeedsAttention(PayoutAttempt $attempt): bool
    {
        $affected = PayoutAttempt::where('id', $attempt->id)
            ->where('status', PayoutAttemptStatus::Pending)
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->update(['status' => PayoutAttemptStatus::NeedsAttention, 'locked_until' => null]);

        return $affected === 1;
    }

    /**
     * A definitive rejection is domain-level and provider-agnostic (checked
     * before ever asking the provider); everything else is delegated to
     * that provider's own classifyFailure().
     */
    private function isRetryable(Throwable $e, PayoutProviderContract $provider): bool
    {
        if ($e instanceof PayoutAttemptMismatchException) {
            return false;
        }

        return $provider->classifyFailure($e) === FailureClass::Retryable;
    }
}
