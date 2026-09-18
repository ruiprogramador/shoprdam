<?php

namespace App\Domain\Payments\Enums;

/**
 * What happened when App\Domain\Payments\Services\ProviderReconciler
 * attempted to reconcile one PaymentAttempt — never a financial outcome,
 * only a reporting/observability one. See
 * docs/financial/RECONCILIATION.md §8/§13/§15.
 */
enum ReconciliationOutcomeType
{
    /** A classification was produced and recorded (or resolved) as a finding. */
    case Observed;

    /**
     * The retrieval attempt did not produce authoritative evidence — either
     * a retryable-shaped failure (timeout, 5xx, connection error), or a
     * non-retryable failure that the resolved provider cannot confirm is
     * specifically "this resource doesn't exist" (auth failure, permission
     * failure, a malformed request, an ambiguous SDK exception, or any
     * definitive rejection from a provider that doesn't implement
     * App\Domain\Payments\Contracts\SupportsConfirmedResourceAbsence at
     * all). Never persisted as a finding, never classified as a financial
     * mismatch, and never touches an already-open finding for this
     * identity — see docs/financial/RECONCILIATION.md §8/§13's evidence
     * contract: "no authoritative evidence -> no financial mismatch
     * finding." Previously named `ProviderUnavailable`, renamed because
     * that name became misleading once it also had to cover permanent,
     * non-retryable-but-inconclusive failures, not just transient outages.
     */
    case RetrievalFailed;

    /** The resolved provider driver doesn't implement SupportsCanonicalRetrieval. */
    case Skipped;
}
