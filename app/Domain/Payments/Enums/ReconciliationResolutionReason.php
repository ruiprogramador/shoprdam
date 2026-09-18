<?php

namespace App\Domain\Payments\Enums;

/**
 * Deliberately a single case in Phase 1 — see
 * docs/financial/RECONCILIATION.md §10/§18. Phase 1 performs no financial
 * corrective action of any kind (§3, §14), so no "corrected via canonical
 * action"-shaped reason is defined or reserved: a future phase that
 * introduces a genuine corrective action would prove its own convergence
 * (mirroring §3's method) and add its own value then, through its own
 * reviewed migration — never guessed at here against an undesigned future.
 */
enum ReconciliationResolutionReason: string
{
    /** The mismatch was simply no longer observed on a later reconciliation pass. */
    case NoLongerObserved = 'no_longer_observed';
}
