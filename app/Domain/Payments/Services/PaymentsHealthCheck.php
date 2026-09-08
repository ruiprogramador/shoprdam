<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\DTOs\PaymentsHealthReport;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes a point-in-time PaymentsHealthReport from nothing but read
 * queries against payment_attempts/payment_provider_events — see that DTO's
 * own docblock for what "actionable" vs. "informational" means. Every
 * method here is a SELECT; none may ever call ->update()/->save()/->delete()/
 * ->increment() on anything — tests/Feature/Domain/Payments/PaymentsHealthCheckTest.php
 * asserts row counts and column values are byte-for-byte unchanged before
 * and after report(), which is the one guarantee this class (and
 * `php artisan payments:health`, its only caller) exists to uphold.
 *
 * Never reads payment_provider_events.payload (a provider's own
 * secret-free-but-still-provider-specific replay reconstruction) or
 * anything provider-credential-shaped — only ids, enum/status values,
 * timestamps, and the already-normalized provider/method/event_type columns
 * ever reach a PaymentsHealthReport, so its toArray() is always safe to log
 * or print (see config('payments.health') for the thresholds driving what
 * counts as "stale"/"repeated" below).
 */
class PaymentsHealthCheck
{
    private const SAMPLE_LIMIT = 20;

    public function report(): PaymentsHealthReport
    {
        $thresholds = $this->thresholds();

        [$needsAttentionCount, $needsAttentionByProvider, $needsAttentionSample] = $this->needsAttention();
        [$stalePendingCount, $stalePendingByProvider, $stalePendingSample] = $this->stalePending($thresholds['stale_pending_minutes']);
        [$staleLeaseCount, $staleLeaseByProvider, $staleLeaseSample] = $this->staleLeases($thresholds['stale_lease_minutes']);
        [$pendingEventCount, $pendingEventByProvider, $oldestPendingEventAgeMinutes] = $this->pendingEvents();
        [$staleEventCount, $staleEventByProvider] = $this->staleEvents($thresholds['stale_event_minutes']);
        [$repeatedReplayFailureCount, $repeatedReplayFailureByProvider, $repeatedReplayFailureSample]
            = $this->repeatedReplayFailures($thresholds['replay_attempts_warning']);

        return new PaymentsHealthReport(
            needsAttentionCount: $needsAttentionCount,
            needsAttentionByProvider: $needsAttentionByProvider,
            needsAttentionSample: $needsAttentionSample,
            stalePendingCount: $stalePendingCount,
            stalePendingByProvider: $stalePendingByProvider,
            stalePendingSample: $stalePendingSample,
            staleLeaseCount: $staleLeaseCount,
            staleLeaseByProvider: $staleLeaseByProvider,
            staleLeaseSample: $staleLeaseSample,
            pendingEventCount: $pendingEventCount,
            pendingEventByProvider: $pendingEventByProvider,
            oldestPendingEventAgeMinutes: $oldestPendingEventAgeMinutes,
            staleEventCount: $staleEventCount,
            staleEventByProvider: $staleEventByProvider,
            repeatedReplayFailureCount: $repeatedReplayFailureCount,
            repeatedReplayFailureByProvider: $repeatedReplayFailureByProvider,
            repeatedReplayFailureSample: $repeatedReplayFailureSample,
            recoveryFailureCount: $this->recoveryFailureCount(),
            eventsEligibleForPruning: $this->eventsEligibleForPruning(),
            providerDistribution: $this->providerDistribution(),
            thresholds: $thresholds,
        );
    }

    /** @return array<string, int> */
    private function thresholds(): array
    {
        return [
            'stale_pending_minutes' => (int) config('payments.health.stale_pending_minutes', 15),
            'stale_lease_minutes' => (int) config('payments.health.stale_lease_minutes', 30),
            'stale_event_minutes' => (int) config('payments.health.stale_event_minutes', 15),
            'replay_attempts_warning' => (int) config('payments.health.replay_attempts_warning', 3),
        ];
    }

    /** @return array{0: int, 1: array<string, int>, 2: list<array<string, mixed>>} */
    private function needsAttention(): array
    {
        $query = PaymentAttempt::query()->where('status', PaymentAttemptStatus::NeedsAttention);

        return [
            (clone $query)->count(),
            $this->countByProvider(clone $query),
            (clone $query)->with('payment')->orderByDesc('id')->limit(self::SAMPLE_LIMIT)->get()
                ->map(fn (PaymentAttempt $attempt) => $this->attemptSample($attempt))
                ->all(),
        ];
    }

    /** @return array{0: int, 1: array<string, int>, 2: list<array<string, mixed>>} */
    private function stalePending(int $staleAfterMinutes): array
    {
        $cutoff = now()->subMinutes($staleAfterMinutes);

        $query = PaymentAttempt::query()
            ->where('status', PaymentAttemptStatus::Pending)
            ->where('created_at', '<=', $cutoff);

        return [
            (clone $query)->count(),
            $this->countByProvider(clone $query),
            (clone $query)->with('payment')->orderBy('created_at')->limit(self::SAMPLE_LIMIT)->get()
                ->map(fn (PaymentAttempt $attempt) => [
                    ...$this->attemptSample($attempt),
                    'age_minutes' => $attempt->created_at->diffInMinutes(now()),
                ])
                ->all(),
        ];
    }

    /**
     * "Unusually old" reconciliation leases: a `pending` attempt currently
     * under lease (`locked_until` set) whose `last_attempted_at` — the
     * moment that lease was (last) acquired — is itself older than
     * `stale_lease_minutes`. An expired-but-recent lease is completely
     * normal between two 5-minute reconciliation ticks (see
     * routes/console.php); this only flags one that's stayed unresolved for
     * multiples of its own --lease-timeout.
     *
     * @return array{0: int, 1: array<string, int>, 2: list<array<string, mixed>>}
     */
    private function staleLeases(int $staleLeaseMinutes): array
    {
        $cutoff = now()->subMinutes($staleLeaseMinutes);

        $query = PaymentAttempt::query()
            ->where('status', PaymentAttemptStatus::Pending)
            ->whereNotNull('locked_until')
            ->where(function (Builder $q) use ($cutoff) {
                $q->whereNull('last_attempted_at')->orWhere('last_attempted_at', '<=', $cutoff);
            });

        return [
            (clone $query)->count(),
            $this->countByProvider(clone $query),
            (clone $query)->with('payment')->orderBy('last_attempted_at')->limit(self::SAMPLE_LIMIT)->get()
                ->map(fn (PaymentAttempt $attempt) => [
                    'id' => $attempt->id,
                    'payment_id' => $attempt->payment_id,
                    'order_id' => $attempt->payment?->order_id,
                    'provider' => $attempt->provider,
                    'method' => $attempt->method,
                    'locked_until' => $attempt->locked_until?->toIso8601String(),
                    'last_attempted_at' => $attempt->last_attempted_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /** @return array{0: int, 1: array<string, int>, 2: int|null} */
    private function pendingEvents(): array
    {
        $query = PaymentProviderEvent::query()->where('status', ProviderEventStatus::Pending);

        $oldestCreatedAt = (clone $query)->min('created_at');

        return [
            (clone $query)->count(),
            $this->countByProvider(clone $query),
            $oldestCreatedAt === null ? null : Carbon::parse($oldestCreatedAt)->diffInMinutes(now()),
        ];
    }

    /** @return array{0: int, 1: array<string, int>} */
    private function staleEvents(int $staleEventMinutes): array
    {
        $cutoff = now()->subMinutes($staleEventMinutes);

        $query = PaymentProviderEvent::query()
            ->where('status', ProviderEventStatus::Pending)
            ->where('created_at', '<=', $cutoff);

        return [
            (clone $query)->count(),
            $this->countByProvider(clone $query),
        ];
    }

    /**
     * Scoped to still-`pending` events on purpose — an Applied event that
     * needed a few replays before it finally resolved is historical noise,
     * not an ongoing problem; only a still-unresolved event repeatedly
     * failing to replay is.
     *
     * @return array{0: int, 1: array<string, int>, 2: list<array<string, mixed>>}
     */
    private function repeatedReplayFailures(int $threshold): array
    {
        $query = PaymentProviderEvent::query()
            ->where('status', ProviderEventStatus::Pending)
            ->where('replay_attempts', '>=', $threshold);

        return [
            (clone $query)->count(),
            $this->countByProvider(clone $query),
            (clone $query)->orderByDesc('replay_attempts')->limit(self::SAMPLE_LIMIT)->get()
                ->map(fn (PaymentProviderEvent $event) => [
                    'id' => $event->id,
                    'provider' => $event->provider,
                    'provider_event_id' => $event->provider_event_id,
                    'event_type' => $event->event_type,
                    'replay_attempts' => $event->replay_attempts,
                    'last_replay_error' => $event->last_replay_error,
                ])
                ->all(),
        ];
    }

    /**
     * Informational, not actionable (see PaymentsHealthReport::isHealthy()):
     * a `pending` attempt that has recorded one recovery failure and is
     * still being retried is an ordinary, expected part of reconciliation —
     * only staleLeases()/stalePending() (this attempt not resolving despite
     * retries) or NeedsAttention (retries exhausted) are.
     */
    private function recoveryFailureCount(): int
    {
        return PaymentAttempt::query()
            ->where('status', PaymentAttemptStatus::Pending)
            ->where('recovery_attempts', '>', 0)
            ->count();
    }

    /** Mirrors App\Console\Commands\PrunePaymentProviderEvents's own eligibility predicate, read-only. */
    private function eventsEligibleForPruning(): int
    {
        $retentionDays = max(0, (int) config('payments.provider_event_retention_days', 90));
        $cutoff = now()->subDays($retentionDays);

        return PaymentProviderEvent::query()
            ->where('status', ProviderEventStatus::Applied)
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $cutoff)
            ->count();
    }

    /**
     * Every PaymentAttempt, grouped by provider and status — answers "which
     * provider, which attempts" (including "failures grouped by provider")
     * from a single aggregate query rather than one query per status.
     *
     * @return array<string, array<string, int>>
     */
    private function providerDistribution(): array
    {
        return PaymentAttempt::query()->toBase()
            ->select('provider', 'status', DB::raw('count(*) as aggregate'))
            ->groupBy('provider', 'status')
            ->get()
            ->groupBy('provider')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [(string) $row->status => (int) $row->aggregate])->all())
            ->all();
    }

    /** @return array<string, int> */
    private function countByProvider(Builder $query): array
    {
        return $query->toBase()
            ->select('provider', DB::raw('count(*) as aggregate'))
            ->groupBy('provider')
            ->pluck('aggregate', 'provider')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /** @return array<string, mixed> */
    private function attemptSample(PaymentAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'payment_id' => $attempt->payment_id,
            'order_id' => $attempt->payment?->order_id,
            'provider' => $attempt->provider,
            'method' => $attempt->method,
            'created_at' => $attempt->created_at->toIso8601String(),
        ];
    }
}
