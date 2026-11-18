<?php

namespace App\Domain\Inventory\Enums;

/**
 * The physical-count state machine — the single definition of which status may
 * follow which (docs/inventory/INVENTORY-CONTROL.md §16):
 *
 *     insert ──▶ Applied              (terminal)
 *     insert ──▶ Stale                (terminal)
 *     insert ──▶ DiscrepancyOpen ──(a later applied count of the same Inventory)──▶ DiscrepancyResolved (terminal)
 *
 * The status is decided once, at insert, under the Inventory row lock. The only
 * later change is the compare-and-set out of DiscrepancyOpen, and it happens only
 * inside the transaction of an applied count. No operator acknowledges or
 * dismisses a discrepancy: only a re-count that can be applied supersedes it.
 */
enum InventoryCountStatus: string
{
    /** The snapshot matched and the observation fits the reservations: the count took effect. */
    case Applied = 'applied';

    /** The snapshot no longer matched `on_hand`: nothing changed, re-count. */
    case Stale = 'stale';

    /** The observation is below the reserved quantity: nothing changed, the evidence awaits a re-count. */
    case DiscrepancyOpen = 'discrepancy_open';

    /** Terminal. A later applied count superseded this discrepancy. */
    case DiscrepancyResolved = 'discrepancy_resolved';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::DiscrepancyOpen => [self::DiscrepancyResolved],
            self::Applied, self::Stale, self::DiscrepancyResolved => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
