<?php

namespace App\Domain\Payments\Enums;

/**
 * Triage priority only — never drives automation. See
 * docs/financial/RECONCILIATION.md §10: severity is its own column,
 * deliberately not folded into ReconciliationStatus, precisely so
 * "how urgent" and "is this actionable" (neither of which Phase 1 has any
 * automatic answer for — see §14) stay independent of "is this still
 * observed."
 */
enum ReconciliationSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
