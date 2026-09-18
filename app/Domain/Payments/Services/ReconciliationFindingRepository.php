<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\DTOs\ReconciliationCandidate;
use App\Domain\Payments\DTOs\ReconciliationClassification;
use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationResolutionReason;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\ReconciliationFinding;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The only class in this codebase allowed to write to
 * payment_reconciliation_findings — implements the bounded-episode
 * lifecycle exactly as designed in docs/financial/RECONCILIATION.md §9/§10.
 *
 * Never mutates a Wallet, a Payment, a PaymentAttempt, an Order, or a
 * PaymentProviderEvent — see tests/Architecture/ReconciliationNoFinancialMutationTest.
 * This class is purely observational: it records evidence, it never acts on
 * it (docs/financial/RECONCILIATION.md §4/§14).
 *
 * The `unique(active_identity)` constraint (§9.1) is the entire CAS backbone
 * — insert-and-recover-on-unique-violation, the identical idiom
 * App\Services\Wallet\WalletTransactionService::record() already uses for a
 * conceptually identical problem. No provider HTTP call ever happens inside
 * any transaction here (§7, §12) — this class only ever receives an
 * already-computed ReconciliationClassification, never calls a provider
 * itself.
 */
class ReconciliationFindingRepository
{
    /**
     * Records one observation. A `Match` classification resolves an
     * already-open episode for this identity, if one exists, and is
     * otherwise a complete no-op — `Match` is never itself persisted as a
     * new row (docs/financial/RECONCILIATION.md §9). Every other
     * classification opens a new episode or updates the currently-open one
     * for this exact `(provider, provider_reference)` identity — never both
     * at once, enforced by `unique(active_identity)`, never by application
     * convention alone.
     */
    public function recordObservation(ReconciliationCandidate $candidate, ReconciliationClassification $classification): ?ReconciliationFinding
    {
        if ($classification->category === ReconciliationCategory::Match) {
            return $this->resolveIfOpen($candidate);
        }

        return $this->openOrUpdateEpisode($candidate, $classification);
    }

    private function resolveIfOpen(ReconciliationCandidate $candidate): ?ReconciliationFinding
    {
        return DB::transaction(function () use ($candidate) {
            $activeIdentity = $this->activeIdentity($candidate);

            $existing = ReconciliationFinding::query()
                ->where('active_identity', $activeIdentity)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                // Nothing was ever open for this identity — a clean MATCH
                // observation is never itself written (§9); there is no
                // episode to resolve.
                return null;
            }

            $existing->update([
                'status' => ReconciliationStatus::Resolved,
                'resolved_at' => now(),
                'resolution_reason' => ReconciliationResolutionReason::NoLongerObserved,
                // Set to NULL in the exact same write that sets
                // resolved_at — atomically frees this identity for a later,
                // unrelated episode (§9.1, §10) without a second statement.
                'active_identity' => null,
                'last_observed_at' => now(),
                'observation_count' => $existing->observation_count + 1,
            ]);

            return $existing->fresh();
        });
    }

    private function openOrUpdateEpisode(ReconciliationCandidate $candidate, ReconciliationClassification $classification): ReconciliationFinding
    {
        return DB::transaction(function () use ($candidate, $classification) {
            $activeIdentity = $this->activeIdentity($candidate);

            $existing = ReconciliationFinding::query()
                ->where('active_identity', $activeIdentity)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->applyObservation($existing, $classification);
            }

            $attributes = $this->newEpisodeAttributes($candidate, $classification, $activeIdentity);

            try {
                return ReconciliationFinding::create($attributes);
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                // Lost the unique(active_identity) race to a concurrent
                // reconciliation pass — the same "insert, recover on the
                // known unique-constraint race" pattern used throughout this
                // codebase (e.g. WalletTransactionService::record()). The
                // winner's row is updated with this observation instead of
                // a second row ever being created.
                $winner = ReconciliationFinding::query()
                    ->where('active_identity', $activeIdentity)
                    ->lockForUpdate()
                    ->first();

                if ($winner === null) {
                    // The winner resolved (and cleared active_identity)
                    // between the failed insert and this re-read — genuinely
                    // rare, and safe to treat as "nothing currently open";
                    // the next reconciliation pass will observe fresh state.
                    throw $e;
                }

                return $this->applyObservation($winner, $classification);
            }
        });
    }

    private function applyObservation(ReconciliationFinding $existing, ReconciliationClassification $classification): ReconciliationFinding
    {
        $existing->update([
            'category' => $classification->category,
            'severity' => $classification->severity,
            'local_state' => $classification->localState,
            'remote_state' => $classification->remoteState,
            'local_amount_minor_units' => $classification->localAmountMinorUnits,
            'remote_amount_minor_units' => $classification->remoteAmountMinorUnits,
            'local_currency' => $classification->localCurrency,
            'remote_currency' => $classification->remoteCurrency,
            'local_correlation_id' => $classification->localCorrelationId,
            'remote_correlation_id' => $classification->remoteCorrelationId,
            'last_observed_at' => now(),
            'observation_count' => $existing->observation_count + 1,
        ]);

        return $existing->fresh();
    }

    /** @return array<string, mixed> */
    private function newEpisodeAttributes(ReconciliationCandidate $candidate, ReconciliationClassification $classification, string $activeIdentity): array
    {
        return [
            'payment_attempt_id' => $candidate->paymentAttemptId,
            'provider' => $candidate->provider,
            'provider_reference' => $candidate->providerReference,
            'active_identity' => $activeIdentity,
            'category' => $classification->category,
            'severity' => $classification->severity,
            'local_state' => $classification->localState,
            'remote_state' => $classification->remoteState,
            'local_amount_minor_units' => $classification->localAmountMinorUnits,
            'remote_amount_minor_units' => $classification->remoteAmountMinorUnits,
            'local_currency' => $classification->localCurrency,
            'remote_currency' => $classification->remoteCurrency,
            'local_correlation_id' => $classification->localCorrelationId,
            'remote_correlation_id' => $classification->remoteCorrelationId,
            'status' => ReconciliationStatus::Open,
            'first_observed_at' => now(),
            'last_observed_at' => now(),
            'observation_count' => 1,
        ];
    }

    private function activeIdentity(ReconciliationCandidate $candidate): string
    {
        return "{$candidate->provider}:{$candidate->providerReference}";
    }

    /** Portable across drivers — mirrors PayoutEventProcessor::isUniqueViolation() exactly. */
    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array($e->getCode(), ['23000', '23505'], true);
    }
}
