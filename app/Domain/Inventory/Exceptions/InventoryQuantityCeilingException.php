<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * An increase would take `on_hand_quantity` past what the column can hold. The
 * conditional UPDATE refused it before the arithmetic could overflow, so this is
 * a clean refusal and the whole transaction rolled back.
 */
class InventoryQuantityCeilingException extends RuntimeException
{
    public static function forInventory(int $inventoryId, int $quantity): self
    {
        return new self("Inventory #{$inventoryId} cannot absorb an increase of {$quantity}: the quantity ceiling would be exceeded.");
    }
}
