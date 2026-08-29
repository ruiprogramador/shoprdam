<?php

namespace App\Console\Commands;

use App\Domain\Payments\Contracts\PaymentProviderContract;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Exceptions\PaymentAttemptMismatchException;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\PaymentProviderManager;
use App\Domain\Payments\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recovers PaymentAttempts where a provider successfully created a remote
 * payment but the process died (or the following DB write failed) before
 * any local claim or pending Wallet transaction was recorded — see
 * PaymentService::finalizeAttempt() and docs/wallet/integrations.md for
 * the gap this closes.
 *
 * Recovery only ever recreates the pending local state (claim + Wallet
 * transaction) by re-issuing the same idempotent provider request under
 * the attempt's own deterministic idempotency key — it never confirms a
 * transaction or moves a Wallet balance. Settlement stays the exclusive
 * responsibility of a provider's own webhook controller, triggered only by
 * the provider telling us the payment actually succeeded. Once a claim is
 * (re)created, PaymentService also replays any terminal provider events
 * that arrived before this attempt was recovered — see
 * PaymentEventProcessor::replayUnmatchedEvents().
 *
 * Provider-agnostic by design: every attempt row carries its own
 * `provider`, resolved through PaymentProviderManager per attempt — this
 * command never imports a provider SDK type or hardcodes provider-specific
 * exception classes itself. Failure classification (retryable or not) is
 * delegated to PaymentProviderContract::classifyFailure() on whichever
 * provider the attempt belongs to.
 *
 * A PaymentAttempt's reconciliation lease (`locked_until`) is a separate
 * concern from its lifecycle `status` — see PaymentAttemptStatus. Every
 * state change this command makes — acquiring the lease, sending an
 * attempt straight to `needs_attention` for being too old, or recording a
 * recovery failure (see acquireLease()/markNeedsAttention()/recordRecoveryFailure())
 * — is a single conditional `UPDATE ... WHERE status = 'pending'`, never a
 * plain `$attempt->update(...)`, so two schedulers/workers racing on the
 * same attempt can't both call a provider for it, and this command can
 * never clobber a valid transition (e.g. finalizeAttempt() committing its
 * claim before a later step throws) that happened after this process last
 * read the row — the database, not a possibly-stale in-memory copy,
 * decides which UPDATE's WHERE still matches.
 *
 * Candidates are streamed via `chunkById()` rather than loaded with `get()`,
 * so this command's memory footprint doesn't grow with the number of
 * orphaned attempts — see `payments.reconciliation_chunk_size`.
 *
 * A second, independent candidate set: any attempt with a known local claim
 * (`provider_reference` not null — `claimed`, `succeeded`, *or* `failed`,
 * deliberately not narrowed to `claimed`) that still has a `pending` row in
 * `payment_provider_events` for that same (provider, provider_reference).
 * This closes a whole class of narrower crash window: both
 * PaymentService::finalizeAttempt() (claim commit, then a separate
 * replayUnmatchedEvents() call) and PaymentEventProcessor::applySucceeded()
 * (settlement commit, then a separate nested replay() call for a
 * refund queued ahead of it — see docs/wallet/integrations.md,
 * "Transaction boundaries") commit a real state change and only *then*
 * replay, as two non-atomic steps. If the process dies (or that later call
 * itself throws — e.g. an uncaught exception from a live webhook, which
 * StripeWebhookController lets surface as a 500 with no retry of its own)
 * between them, the attempt already carries its real, correct status
 * (`claimed` *or already terminal*) but its queued event was never
 * replayed, and nothing else re-triggers replay for it (a provider won't
 * redeliver a webhook it already got a 200 for). Recovering this never
 * reopens the attempt or touches its `status`/lease, whatever that status
 * is — the claim/settlement already happened and is not being retried,
 * only the event replay is — it just re-runs finalizeAttempt(), which for
 * an attempt that already has a provider_reference skips the provider call
 * entirely and goes straight to replayUnmatchedEvents(), itself required
 * to be idempotent and safe to call repeatedly (see PaymentEventProcessor).
 * A failure here is left for the *event* row's own
 * `replay_attempts`/`last_replay_error` bookkeeping to surface — see
 * processAttemptWithUnmatchedEvents().
 */
class ReconcileOrphanedPaymentAttempts extends Command
{
    protected $signature = 'app:reconcile-orphaned-payment-attempts
        {--stale-after=5 : Minutes an attempt must have been pending before it is considered orphaned}
        {--max-attempts=5 : Recovery attempts before an attempt is marked needs_attention and left alone}
        {--max-age=720 : Minutes since an attempt was created before it is marked needs_attention regardless of --max-attempts, kept well under a provider\'s idempotency key retention window so a repeat request is never treated as a new one}
        {--lease-timeout=15 : Minutes a leased attempt is given to resolve before another run is allowed to reclaim it, e.g. after the worker that leased it died}';

    protected $description = 'Recover Payments whose provider payment was created but never got a local claim or Wallet transaction';

    public function handle(PaymentService $paymentService, PaymentProviderManager $providers): int
    {
        $staleAfter = (int) $this->option('stale-after');
        $maxAttempts = (int) $this->option('max-attempts');
        $maxAge = (int) $this->option('max-age');
        $leaseTimeout = (int) $this->option('lease-timeout');

        if (! $this->validateOptions($staleAfter, $maxAttempts, $maxAge, $leaseTimeout)) {
            return self::INVALID;
        }

        $chunkSize = max(1, (int) config('payments.reconciliation_chunk_size', 200));
        $foundAny = false;

        PaymentAttempt::query()
            ->where('status', PaymentAttemptStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes($staleAfter))
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->with('payment.order')
            ->chunkById(
                $chunkSize,
                function ($candidates) use (&$foundAny, $paymentService, $providers, $maxAttempts, $maxAge, $leaseTimeout) {
                    $foundAny = true;

                    foreach ($candidates as $attempt) {
                        $this->processAttempt($attempt, $paymentService, $providers, $maxAttempts, $maxAge, $leaseTimeout);
                    }
                }
            );

        $eventsTable = (new PaymentProviderEvent)->getTable();

        PaymentAttempt::query()
            // Deliberately not `where('status', Claimed)` — see the class
            // docblock. Any attempt with an established claim (claimed,
            // succeeded, or failed) can have a stuck unmatched event; the
            // status this attempt happens to be in isn't what determines
            // eligibility here, having a provider_reference is.
            ->whereNotNull('provider_reference')
            ->whereExists(function ($query) use ($eventsTable, $staleAfter) {
                $query->selectRaw('1')
                    ->from($eventsTable)
                    ->whereColumn("{$eventsTable}.provider", 'payment_attempts.provider')
                    ->whereColumn("{$eventsTable}.provider_reference", 'payment_attempts.provider_reference')
                    ->where("{$eventsTable}.status", ProviderEventStatus::Pending)
                    ->where("{$eventsTable}.created_at", '<=', now()->subMinutes($staleAfter));
            })
            ->chunkById(
                $chunkSize,
                function ($candidates) use (&$foundAny, $paymentService) {
                    $foundAny = true;

                    foreach ($candidates as $attempt) {
                        $this->processAttemptWithUnmatchedEvents($attempt, $paymentService);
                    }
                }
            );

        if (! $foundAny) {
            $this->info('No orphaned payment attempts or unresolved provider events found.');
        }

        return self::SUCCESS;
    }

    /**
     * `--stale-after` and `--lease-timeout` feed directly into `subMinutes()`
     * calls that decide which attempts get picked up and how long a lease
     * lasts; `--max-attempts` gates how many times a provider gets called
     * for a failing attempt. Silently accepting a nonsensical value
     * (negative, zero where that doesn't make sense, or `--max-age` no
     * wider than `--stale-after`) wouldn't error, it would just misbehave.
     */
    private function validateOptions(int $staleAfter, int $maxAttempts, int $maxAge, int $leaseTimeout): bool
    {
        $errors = [];

        if ($staleAfter < 0) {
            $errors[] = '--stale-after must be >= 0 minutes.';
        }

        if ($maxAttempts < 1) {
            $errors[] = '--max-attempts must be >= 1.';
        }

        if ($leaseTimeout <= 0) {
            $errors[] = '--lease-timeout must be > 0 minutes.';
        }

        if ($maxAge <= $staleAfter) {
            $errors[] = "--max-age ({$maxAge}) must be greater than --stale-after ({$staleAfter}) — otherwise every orphaned attempt would be marked needs_attention on sight instead of ever being retried.";
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        return $errors === [];
    }

    private function processAttempt(
        PaymentAttempt $attempt,
        PaymentService $paymentService,
        PaymentProviderManager $providers,
        int $maxAttempts,
        int $maxAge,
        int $leaseTimeout,
    ): void {
        $ageMinutes = $attempt->created_at->diffInMinutes(now());

        if ($ageMinutes >= $maxAge) {
            if (! $this->markNeedsAttention($attempt)) {
                // Another worker already leased or resolved this attempt.
                return;
            }

            Log::error('Payment attempt reconciliation exceeded the safe recovery window, needs manual attention.', [
                ...$this->logContext($attempt, ['age_minutes' => $ageMinutes, 'max_age' => $maxAge]),
                'outcome' => 'needs_attention',
            ]);
            $this->error("Payment attempt #{$attempt->id} needs manual attention: older than the {$maxAge}-minute safe recovery window.");

            return;
        }

        if (! $this->acquireLease($attempt, $leaseTimeout)) {
            // Another worker already leased this attempt (or resolved it)
            // between the query above and this UPDATE.
            return;
        }

        try {
            $paymentService->finalizeAttempt($attempt);

            Log::info('Payment attempt reconciliation recovered.', [
                ...$this->logContext($attempt),
                'outcome' => 'claimed',
            ]);
            $this->info("Recovered payment attempt #{$attempt->id} ({$attempt->idempotency_key}).");
        } catch (Throwable $e) {
            $retryable = $this->isRetryable($e, $providers->driver($attempt->provider));
            $recoveryAttempts = $attempt->recovery_attempts + 1;
            $exhausted = $recoveryAttempts >= $maxAttempts;

            $updated = $this->recordRecoveryFailure($attempt, $e, (! $retryable || $exhausted)
                ? ['status' => PaymentAttemptStatus::NeedsAttention, 'locked_until' => null]
                // Releases the lease this run took via acquireLease() above,
                // so a later run's --stale-after query can pick it up
                // again (created_at, which that query filters on, is
                // unchanged).
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
                Log::warning('Payment attempt reconciliation raised after the attempt had already left pending; leaving its real state untouched.', [
                    ...$this->logContext($attempt, ['exception' => $e::class]),
                    'outcome' => 'left_as_is',
                ]);
                $this->error("Payment attempt #{$attempt->id} already progressed past pending before this error; not overwriting its state ({$e->getMessage()}).");

                return;
            }

            $context = $this->logContext($attempt, ['exception' => $e::class, 'recovery_attempts' => $recoveryAttempts]);

            if (! $retryable || $exhausted) {
                $reason = $retryable
                    ? "exhausted retries ({$recoveryAttempts}/{$maxAttempts})"
                    : 'hit a non-retryable error ('.$e::class.')';

                Log::error("Payment attempt reconciliation {$reason}, needs manual attention.", [
                    ...$context,
                    'outcome' => 'needs_attention',
                ]);
                $this->error("Payment attempt #{$attempt->id} needs manual attention — {$reason}: {$e->getMessage()}");
            } else {
                Log::warning('Payment attempt reconciliation failed, will retry.', [
                    ...$context,
                    'outcome' => 'retry_pending',
                ]);
                $this->error("Failed to recover payment attempt #{$attempt->id} (attempt {$recoveryAttempts}/{$maxAttempts}): {$e->getMessage()}");
            }
        }
    }

    /**
     * Recovers an attempt (claimed, succeeded, or failed — see the class
     * docblock's second candidate set) whose queued webhook was never
     * replayed. Deliberately never touches the attempt's `status` or
     * lease, whatever it currently is: the claim/settlement already
     * happened and is not being retried, only the (idempotent,
     * safe-to-repeat) replay of its still-`pending` provider event is. A
     * failure here is intentionally not written back onto the
     * PaymentAttempt row — PaymentEventProcessor::replay() already records
     * it on the event row itself (`replay_attempts`/`last_replay_error`),
     * which is what the next run's still-`pending` status will pick up
     * again.
     */
    private function processAttemptWithUnmatchedEvents(PaymentAttempt $attempt, PaymentService $paymentService): void
    {
        try {
            $paymentService->finalizeAttempt($attempt);

            Log::info('Replayed a pending provider event queued against an already-claimed payment attempt.', [
                ...$this->logContext($attempt),
                'outcome' => 'replayed',
            ]);
            $this->info("Replayed queued provider event(s) for payment attempt #{$attempt->id} ({$attempt->idempotency_key}).");
        } catch (Throwable $e) {
            Log::warning('Replaying a pending provider event for an already-claimed payment attempt failed; left for the next run.', [
                ...$this->logContext($attempt, ['exception' => $e::class]),
                'outcome' => 'replay_failed',
            ]);
            $this->error("Failed to replay queued provider event(s) for payment attempt #{$attempt->id}: {$e->getMessage()}");
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
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(PaymentAttempt $attempt, array $extra = []): array
    {
        return [
            'attempt_id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'order_id' => $attempt->payment?->order_id,
            'provider' => $attempt->provider,
            'method' => $attempt->method,
            'idempotency_key' => $attempt->idempotency_key,
            'recovery_attempts' => $attempt->recovery_attempts,
            ...$extra,
        ];
    }

    /**
     * Atomically acquires (or renews, if already expired) the reconciliation
     * lease on one attempt, re-checking its eligibility against the
     * database's *current* state rather than the possibly-stale copy this
     * process read earlier. Returns whether this call was the one that won it.
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
     * on every retry — burning through `--max-attempts` against it only
     * delays `needs_attention` and wastes provider calls.
     * PaymentAttemptMismatchException is a domain-level, provider-agnostic
     * concern (see App\Domain\Payments\Services\PaymentService), checked
     * before ever asking the provider; everything else is delegated to
     * that provider's own classifyFailure().
     */
    private function isRetryable(Throwable $e, PaymentProviderContract $provider): bool
    {
        if ($e instanceof PaymentAttemptMismatchException) {
            return false;
        }

        return $provider->classifyFailure($e) === FailureClass::Retryable;
    }
}
