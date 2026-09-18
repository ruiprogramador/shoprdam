<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Contracts\SupportsCanonicalRetrieval;
use App\Domain\Payments\Contracts\SupportsConfirmedResourceAbsence;
use App\Domain\Payments\DTOs\ReconciliationCandidate;
use App\Domain\Payments\DTOs\ReconciliationOutcome;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\MinorUnits;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\PaymentProviderManager;
use Throwable;

/**
 * Orchestrates Phase 1 (Direction 1: local -> provider) reconciliation for
 * one PaymentAttempt at a time — see docs/financial/RECONCILIATION.md §7's
 * architecture diagram, which this class implements exactly:
 *
 *   durable local read (no lock) -> retrieveByReference() (no transaction,
 *   read-only, idempotent) -> pure classification (no I/O) -> short
 *   transactional episode upsert -> structured observation, nothing else.
 *
 * Deliberately does NOT depend on PaymentAttemptRecoveryService,
 * PaymentService, or PaymentEventProcessor for anything beyond the
 * read-only model relations already loaded here — see
 * docs/financial/RECONCILIATION.md §3/§5/§14 for why no automatic financial
 * action exists in Phase 1, and
 * tests/Architecture/ReconciliationNoFinancialMutationTest for the
 * mechanical proof this class never calls any of them.
 *
 * Evidence contract (docs/financial/RECONCILIATION.md §8/§13): a retrieval
 * failure only ever becomes RemoteMissing evidence when the resolved
 * provider both implements SupportsConfirmedResourceAbsence and confirms,
 * for that exact exception, that the resource specifically doesn't exist.
 * `FailureClass::NonRetryable` alone is not sufficient — it also covers
 * authentication/permission failures, malformed requests, and other
 * definitive rejections that prove nothing about whether the resource
 * exists. Every other retrieval failure (retryable or not) is a
 * `RetrievalFailed` outcome: never persisted as a finding, and never
 * touches an already-open one — no authoritative evidence means no
 * financial mismatch claim, full stop.
 */
class ProviderReconciler
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly ReconciliationClassifier $classifier,
        private readonly ReconciliationFindingRepository $findings,
    ) {}

    /**
     * Reconciles a single PaymentAttempt. Requires `$attempt->provider_reference`
     * to be set — an attempt never claimed by a provider has nothing to
     * retrieve by reference, so candidate selection
     * (App\Console\Commands\ReconcilePaymentsAgainstProvider) never offers
     * one here.
     */
    public function reconcile(PaymentAttempt $attempt): ReconciliationOutcome
    {
        $provider = $this->providers->driver($attempt->provider);

        if (! $provider instanceof SupportsCanonicalRetrieval) {
            return ReconciliationOutcome::skipped(
                "provider '{$attempt->provider}' does not implement SupportsCanonicalRetrieval",
            );
        }

        // Provider HTTP call — deliberately outside any database
        // transaction (docs/financial/RECONCILIATION.md §12). A read-only
        // GET, idempotent by nature: calling it more than once for the same
        // reference is financially harmless (§11.1).
        try {
            $result = $provider->retrieveByReference($attempt->provider_reference);
        } catch (Throwable $e) {
            $failureClass = $provider->classifyFailure($e);

            if ($failureClass === FailureClass::Retryable) {
                // No authoritative evidence at all — never a financial
                // mismatch, never persisted, and never touches an
                // already-open finding for this identity (§8/§13).
                return ReconciliationOutcome::retrievalFailed($e, retryable: true);
            }

            // `FailureClass::NonRetryable` is a much broader bucket than
            // "confirmed this resource doesn't exist" — it also covers
            // authentication failure, permission failure, a malformed
            // request, a card error, an idempotency conflict, none of which
            // prove absence (docs/financial/RECONCILIATION.md §8/§13). Only
            // a provider that can specifically confirm absence for this
            // exact exception may this fall through to classification as
            // RemoteMissing; every other non-retryable failure is equally
            // inconclusive and must not touch a finding at all.
            if (! $provider instanceof SupportsConfirmedResourceAbsence || ! $provider->isConfirmedAbsent($e)) {
                return ReconciliationOutcome::retrievalFailed($e, retryable: false);
            }

            $result = null;
        }

        $order = $attempt->payment->order;

        $candidate = new ReconciliationCandidate(
            paymentAttemptId: $attempt->id,
            provider: $attempt->provider,
            providerReference: $attempt->provider_reference,
            localAttemptStatus: $attempt->status,
            expectedAmountMinorUnits: MinorUnits::fromDecimal($order->amount),
            expectedCurrency: strtolower($order->currency->code),
            expectedCorrelationId: (string) $order->id,
            hasPendingProviderEvent: $this->hasPendingProviderEvent($attempt),
        );

        $classification = $this->classifier->classify($candidate, $result);

        $finding = $this->findings->recordObservation($candidate, $classification);

        return ReconciliationOutcome::observed($finding);
    }

    /**
     * A plain read against payment_provider_events — never a write, never a
     * replay trigger. See docs/financial/RECONCILIATION.md §13/§8: this is
     * what distinguishes RemoteSucceededAwaitingReplay (already
     * self-resolving via the existing scheduler, §3.D) from
     * RemoteSucceededNoSettlementPath (no existing convergence path, §3.A/§3.B).
     */
    private function hasPendingProviderEvent(PaymentAttempt $attempt): bool
    {
        return PaymentProviderEvent::query()
            ->where('provider', $attempt->provider)
            ->where('provider_reference', $attempt->provider_reference)
            ->where('status', ProviderEventStatus::Pending)
            ->exists();
    }
}
