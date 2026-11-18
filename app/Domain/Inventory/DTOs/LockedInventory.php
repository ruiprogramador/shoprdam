<?php

namespace App\Domain\Inventory\DTOs;

/**
 * An Inventory row as returned by the locking read of the current transaction: a
 * current read, taken while holding the row exclusively. Every decision or
 * evidence value an operation needs from the Inventory comes from here
 * (docs/inventory/INVENTORY-CONTROL.md §10.5).
 */
final readonly class LockedInventory
{
    public function __construct(
        public int $id,
        public int $onHand,
        public int $reserved,
        public bool $isBaselined,
    ) {}
}
