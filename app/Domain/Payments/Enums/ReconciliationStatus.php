<?php

namespace App\Domain\Payments\Enums;

/**
 * A ReconciliationFinding episode's only truth-about-the-mismatch axis — see
 * docs/financial/RECONCILIATION.md §10. Deliberately just two values: there
 * is no `needs_attention` distinct from `open` (severity, its own column,
 * is what differentiates urgency — see ReconciliationSeverity), and
 * acknowledgement (`acknowledged_at`/`acknowledged_by` on
 * App\Domain\Payments\Models\ReconciliationFinding) is a fully independent
 * axis that never changes this one. `Resolved` never implies a financial
 * correction happened — see ReconciliationResolutionReason.
 */
enum ReconciliationStatus: string
{
    /** A mismatch is currently believed to exist. */
    case Open = 'open';

    /** The mismatch is no longer observed. Never set by acknowledgement alone. */
    case Resolved = 'resolved';
}
