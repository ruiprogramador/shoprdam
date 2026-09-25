<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\DTOs\ReconciliationCandidate;
use App\Domain\Payments\DTOs\ReconciliationClassification;
use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationResolutionReason;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Exceptions\ReconciliationEpisodeRaceUnresolvedException;
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
 * — a savepoint-protected insert-and-recover, the identical PostgreSQL-safe
 * idiom App\Services\Wallet\WalletTransactionService::record() uses for a
 * conceptually identical problem (see openOrUpdateEpisode()'s own docblock).
 * No provider HTTP call ever happens inside any transaction here (§7, §12)
 * — this class only ever receives an already-computed
 * ReconciliationClassification, never calls a provider itself.
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

    /**
     * PostgreSQL-safe idempotent-insert algorithm, savepoint-based — the
     * same one App\Services\Wallet\WalletTransactionService::record() uses,
     * for the same reason (see that method's own docblock for the full
     * reasoning): an earlier version of this method used `insertOrIgnore()`,
     * reconsidered after an adversarial audit found MySQL/MariaDB's
     * `IGNORE` modifier cancels strict-mode error escalation for its one
     * statement, so it can silently insert a *coerced/truncated* row
     * (e.g. a `local_state`/`remote_state` value over 40 characters) rather
     * than rejecting it — a real risk `create()` never had. `create()` is
     * wrapped in its own, inner `DB::transaction()` instead, making it a
     * real, targeted savepoint: a genuine `unique(active_identity)`
     * violation rolls back to that savepoint (verified against the
     * installed Laravel version — see
     * WalletTransactionService::record()'s docblock for the exact
     * mechanism) before this method's own `catch` ever runs, so the
     * re-read below never touches a transaction PostgreSQL has aborted, on
     * any engine, and every value is validated exactly as strictly as
     * `create()` always was.
     */
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
                // Its own savepoint (see this method's own docblock) — a
                // real ROLLBACK TO SAVEPOINT runs, undoing only this insert
                // attempt, before the catch below ever executes.
                return DB::transaction(fn () => ReconciliationFinding::create($attributes));
            } catch (QueryException $e) {
                if (! $this->isActiveIdentityUniqueViolation($e)) {
                    throw $e;
                }

                // Lost the unique(active_identity) race to a concurrent
                // reconciliation pass — the savepoint above already rolled
                // this attempt back, so this SELECT runs on a healthy
                // transaction on every engine, including PostgreSQL.
                $winner = ReconciliationFinding::query()
                    ->where('active_identity', $activeIdentity)
                    ->lockForUpdate()
                    ->first();

                if ($winner === null) {
                    // The winner resolved (and cleared active_identity)
                    // between the failed insert and this re-read — see
                    // ReconciliationEpisodeRaceUnresolvedException's own
                    // docblock for why this is safe to fail closed on
                    // rather than retry inline.
                    throw ReconciliationEpisodeRaceUnresolvedException::forIdentity($activeIdentity);
                }

                return $this->applyObservation($winner, $classification);
            }
        });
    }

    /** Portable across drivers — mirrors InventoryReservationService::isReservationIdentityViolation()'s precision. */
    private function isActiveIdentityUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($e->getMessage(), 'active_identity');
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
}
