<?php

namespace App\Domain\Payments\DTOs;

/**
 * A point-in-time snapshot of operational payment state — what
 * App\Domain\Payments\Services\PaymentsHealthCheck computes and
 * `php artisan payments:health` renders. Every count/list here comes from a
 * read-only query; nothing that produces one ever mutates a financial
 * record (see that command's own docblock).
 *
 * Split into `*ActionableCount`-style fields (each one, alone, is worth an
 * operator's attention — see isHealthy()) and purely informational ones
 * (`pendingEventCount`, `providerDistribution`, `eventsEligibleForPruning`,
 * `recoveryFailureCount`) that answer "what's going on" without ever, by
 * themselves, flipping isHealthy() to false — a handful of pending events or
 * one recorded retry is completely normal traffic, not an incident; see
 * config('payments.health') for the thresholds that separate "normal" from
 * "actionable" for each one.
 */
final readonly class PaymentsHealthReport
{
    /**
     * @param  array<string, int>  $needsAttentionByProvider
     * @param  list<array{id: int, payment_id: int, order_id: int|null, provider: string, method: string, created_at: string}>  $needsAttentionSample
     * @param  array<string, int>  $stalePendingByProvider
     * @param  list<array{id: int, payment_id: int, order_id: int|null, provider: string, method: string, created_at: string, age_minutes: int}>  $stalePendingSample
     * @param  array<string, int>  $staleLeaseByProvider
     * @param  list<array{id: int, payment_id: int, order_id: int|null, provider: string, method: string, locked_until: string|null, last_attempted_at: string|null}>  $staleLeaseSample
     * @param  array<string, int>  $pendingEventByProvider
     * @param  array<string, int>  $staleEventByProvider
     * @param  array<string, int>  $repeatedReplayFailureByProvider
     * @param  list<array{id: int, provider: string, provider_event_id: string, event_type: string, replay_attempts: int, last_replay_error: string|null}>  $repeatedReplayFailureSample
     * @param  array<string, array<string, int>>  $providerDistribution  provider => [status => count]
     * @param  array<string, int>  $thresholds  the config('payments.health') values this report was computed against
     */
    public function __construct(
        public int $needsAttentionCount,
        public array $needsAttentionByProvider,
        public array $needsAttentionSample,
        public int $stalePendingCount,
        public array $stalePendingByProvider,
        public array $stalePendingSample,
        public int $staleLeaseCount,
        public array $staleLeaseByProvider,
        public array $staleLeaseSample,
        public int $pendingEventCount,
        public array $pendingEventByProvider,
        public ?int $oldestPendingEventAgeMinutes,
        public int $staleEventCount,
        public array $staleEventByProvider,
        public int $repeatedReplayFailureCount,
        public array $repeatedReplayFailureByProvider,
        public array $repeatedReplayFailureSample,
        public int $recoveryFailureCount,
        public int $eventsEligibleForPruning,
        public array $providerDistribution,
        public array $thresholds,
    ) {}

    /**
     * Whether an operator needs to look at anything right now. Deliberately
     * excludes every purely informational field (see the class docblock) —
     * a nonzero pendingEventCount or recoveryFailureCount alone never makes
     * this false, only the "stale" / "repeated" variants of them do.
     */
    public function isHealthy(): bool
    {
        return $this->needsAttentionCount === 0
            && $this->stalePendingCount === 0
            && $this->staleLeaseCount === 0
            && $this->staleEventCount === 0
            && $this->repeatedReplayFailureCount === 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'needs_attention' => [
                'count' => $this->needsAttentionCount,
                'by_provider' => $this->needsAttentionByProvider,
                'sample' => $this->needsAttentionSample,
            ],
            'stale_pending_attempts' => [
                'count' => $this->stalePendingCount,
                'by_provider' => $this->stalePendingByProvider,
                'sample' => $this->stalePendingSample,
            ],
            'stale_reconciliation_leases' => [
                'count' => $this->staleLeaseCount,
                'by_provider' => $this->staleLeaseByProvider,
                'sample' => $this->staleLeaseSample,
            ],
            'pending_provider_events' => [
                'count' => $this->pendingEventCount,
                'by_provider' => $this->pendingEventByProvider,
                'oldest_age_minutes' => $this->oldestPendingEventAgeMinutes,
            ],
            'stale_provider_events' => [
                'count' => $this->staleEventCount,
                'by_provider' => $this->staleEventByProvider,
            ],
            'repeated_replay_failures' => [
                'count' => $this->repeatedReplayFailureCount,
                'by_provider' => $this->repeatedReplayFailureByProvider,
                'sample' => $this->repeatedReplayFailureSample,
            ],
            'recovery_failure_count' => $this->recoveryFailureCount,
            'events_eligible_for_pruning' => $this->eventsEligibleForPruning,
            'provider_distribution' => $this->providerDistribution,
            'thresholds' => $this->thresholds,
        ];
    }
}
