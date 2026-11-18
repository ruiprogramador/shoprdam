<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * A control operation reached an Inventory whose evidence has no starting point:
 * legacy stock must be adopted first. Decided from the row as read under its
 * lock. Nothing was changed.
 */
class InventoryNotBaselinedException extends RuntimeException
{
    public static function forInventory(int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId} has no baseline; a legacy Inventory must be adopted before any control operation.");
    }
}
