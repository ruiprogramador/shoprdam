<?php

namespace App\Console\Commands;

use App\Domain\Payments\ConfigInteger;
use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Enums\RecoveryOutcome;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\Services\PayoutAttemptRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recovers PayoutAttempts stuck `pending` — created durably before the
 * provider was ever contacted (see PayoutService::createDurableAttempt()),
 * but never claimed because the process died (or the following DB write
 * failed) before that could happen. Mirrors
 * App\Console\Commands\ReconcileOrphanedPaymentAttempts exactly, minus its
 * second query: this domain has no unmatched-event inbox (see
 * PayoutEventProcessor's own docblock for why) — there is nothing else to
 * reconcile besides the claim step itself.
 *
 * Recovery only ever re-claims a provider_reference by re-issuing the same
 * idempotent request under the attempt's own deterministic idempotency
 * key — it never settles or reverses anything. That stays the exclusive
 * responsibility of PayoutEventProcessor::apply(), triggered only by real
 * evidence (a manual confirmation today).
 *
 * The actual per-attempt recovery algorithm lives in
 * App\Domain\Payouts\Services\PayoutAttemptRecoveryService, shared with
 * App\Http\Controllers\Admin\PayoutRecoveryController's retry action — see
 * that service's own docblock for the concurrency guarantee this split
 * exists to preserve.
 */
class ReconcileOrphanedPayoutAttempts extends Command
{
    protected $signature = 'app:reconcile-orphaned-payout-attempts
        {--stale-after=5 : Minutes an attempt must have been pending before it is considered orphaned}
        {--max-attempts=5 : Recovery attempts before an attempt is marked needs_attention and left alone}
        {--max-age=720 : Minutes since an attempt was created before it is marked needs_attention regardless of --max-attempts}
        {--lease-timeout=15 : Minutes a leased attempt is given to resolve before another run is allowed to reclaim it}';

    protected $description = 'Recover Payout attempts whose durable pre-provider-call record was written but never claimed';

    public function handle(PayoutAttemptRecoveryService $recovery): int
    {
        $staleAfter = $this->parseOption('stale-after');
        $maxAttempts = $this->parseOption('max-attempts');
        $maxAge = $this->parseOption('max-age');
        $leaseTimeout = $this->parseOption('lease-timeout');

        if (in_array(null, [$staleAfter, $maxAttempts, $maxAge, $leaseTimeout], true)) {
            return self::INVALID;
        }

        if (! $this->validateOptions($staleAfter, $maxAttempts, $maxAge, $leaseTimeout)) {
            return self::INVALID;
        }

        $chunkSize = max(1, (int) config('payouts.reconciliation_chunk_size', 200));
        $foundAny = false;

        PayoutAttempt::query()
            ->where('status', PayoutAttemptStatus::Pending)
            ->where('created_at', '<=', now()->subMinutes($staleAfter))
            ->where(function ($query) {
                $query->whereNull('locked_until')->orWhere('locked_until', '<=', now());
            })
            ->with('payout')
            ->chunkById(
                $chunkSize,
                function ($candidates) use (&$foundAny, $recovery, $maxAttempts, $maxAge, $leaseTimeout) {
                    $foundAny = true;

                    foreach ($candidates as $attempt) {
                        $this->processAttempt($attempt, $recovery, $maxAttempts, $maxAge, $leaseTimeout);
                    }
                }
            );

        if (! $foundAny) {
            $this->info('No orphaned payout attempts found.');
        }

        return self::SUCCESS;
    }

    private function parseOption(string $name): ?int
    {
        $raw = $this->option($name);
        $parsed = ConfigInteger::parse($raw, min: PHP_INT_MIN);

        if ($parsed === null) {
            $this->error("--{$name} must be an integer. Got: '".ConfigInteger::printable($raw)."'.");
        }

        return $parsed;
    }

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
            $errors[] = "--max-age ({$maxAge}) must be greater than --stale-after ({$staleAfter}).";
        }

        foreach ($errors as $error) {
            $this->error($error);
        }

        return $errors === [];
    }

    private function processAttempt(
        PayoutAttempt $attempt,
        PayoutAttemptRecoveryService $recovery,
        int $maxAttempts,
        int $maxAge,
        int $leaseTimeout,
    ): void {
        $result = $recovery->recover($attempt, $maxAttempts, $maxAge, $leaseTimeout);

        switch ($result->outcome) {
            case RecoveryOutcome::Skipped:
                return;

            case RecoveryOutcome::AgeExceeded:
                Log::error('Payout attempt reconciliation exceeded the safe recovery window, needs manual attention.', [
                    ...$this->logContext($attempt, ['age_minutes' => $result->ageMinutes, 'max_age' => $result->maxAge]),
                    'outcome' => 'needs_attention',
                ]);
                $this->error("Payout attempt #{$attempt->id} needs manual attention: older than the {$result->maxAge}-minute safe recovery window.");

                return;

            case RecoveryOutcome::Recovered:
                Log::info('Payout attempt reconciliation recovered.', [
                    ...$this->logContext($attempt),
                    'outcome' => 'claimed',
                ]);
                $this->info("Recovered payout attempt #{$attempt->id} ({$attempt->idempotency_key}).");

                return;

            case RecoveryOutcome::AlreadyProgressed:
                Log::warning('Payout attempt reconciliation raised after the attempt had already left pending; leaving its real state untouched.', [
                    ...$this->logContext($attempt, ['exception' => $result->exception::class]),
                    'outcome' => 'left_as_is',
                ]);
                $this->error("Payout attempt #{$attempt->id} already progressed past pending before this error; not overwriting its state ({$result->exception->getMessage()}).");

                return;

            case RecoveryOutcome::NeedsAttention:
                $reason = $result->retryable
                    ? "exhausted retries ({$result->recoveryAttempts}/{$result->maxAttempts})"
                    : 'hit a non-retryable error ('.$result->exception::class.')';

                Log::error("Payout attempt reconciliation {$reason}, needs manual attention.", [
                    ...$this->logContext($attempt, ['exception' => $result->exception::class, 'recovery_attempts' => $result->recoveryAttempts]),
                    'outcome' => 'needs_attention',
                ]);
                $this->error("Payout attempt #{$attempt->id} needs manual attention — {$reason}: {$result->exception->getMessage()}");

                return;

            case RecoveryOutcome::RetryPending:
                Log::warning('Payout attempt reconciliation failed, will retry.', [
                    ...$this->logContext($attempt, ['exception' => $result->exception::class, 'recovery_attempts' => $result->recoveryAttempts]),
                    'outcome' => 'retry_pending',
                ]);
                $this->error("Failed to recover payout attempt #{$attempt->id} (attempt {$result->recoveryAttempts}/{$result->maxAttempts}): {$result->exception->getMessage()}");

                return;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(PayoutAttempt $attempt, array $extra = []): array
    {
        return [
            'attempt_id' => $attempt->id,
            'payout_id' => $attempt->payout_id,
            'provider' => $attempt->provider,
            'idempotency_key' => $attempt->idempotency_key,
            'recovery_attempts' => $attempt->recovery_attempts,
            ...$extra,
        ];
    }
}
