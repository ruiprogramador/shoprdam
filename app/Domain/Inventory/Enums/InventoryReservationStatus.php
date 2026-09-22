<?php

namespace App\Domain\Inventory\Enums;

/**
 * The reservation state machine — the single definition of which state may
 * follow which (docs/inventory/INVENTORY-RESERVATIONS.md §6):
 *
 *     Reserved ──commit──▶ Committed   (terminal: stock consumed)
 *        └────release───▶ Released     (terminal: stock made available again)
 *
 * The backing value IS `inventory_reservations.status`, constrained by a
 * database CHECK to exactly these three. There is deliberately no `Expired`
 * (expiry is not implemented — see the design document §12) and no
 * `Cancelled` (no cancellation exists).
 *
 * Same-state repeats are not transitions: InventoryReservationService treats
 * them as idempotent no-ops. A terminal state never leads anywhere — a
 * Committed reservation is never released and a Released one never committed.
 */
enum InventoryReservationStatus: string
{
    /** Units are held: counted in `inventories.reserved_quantity`, not yet consumed. */
    case Reserved = 'reserved';

    /** Terminal. The units were consumed: `on_hand_quantity` and `reserved_quantity` both dropped. */
    case Committed = 'committed';

    /** Terminal. The hold was given back: `reserved_quantity` dropped, `on_hand_quantity` untouched. */
    case Released = 'released';

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reserved => [self::Committed, self::Released],
            self::Committed, self::Released => [],
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
