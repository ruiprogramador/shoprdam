<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * A reservation, write-off or reversal was refused because the atomic
 * conditional update found `on_hand_quantity - reserved_quantity < requested` at
 * the moment it ran. A normal, expected business outcome — not corruption. Thrown
 * from inside the operation's transaction, so it rolls everything back, including
 * every line already reserved for the same Order.
 */
class InsufficientStockException extends RuntimeException
{
    public static function forProduct(int $productId, int $requested): self
    {
        return new self("Insufficient available stock for Product #{$productId} (requested {$requested}).");
    }

    /** Only `available` may be written off: reserved stock is never consumed. */
    public static function forWriteOff(int $inventoryId, int $requested): self
    {
        return new self("Inventory #{$inventoryId} cannot write off {$requested}: it exceeds the available stock.");
    }

    /** Reversing a receipt is a decrease under the same rule: stock since reserved is never un-received. */
    public static function forCorrection(int $inventoryId, int $requested): self
    {
        return new self("Inventory #{$inventoryId} cannot reverse {$requested}: it exceeds the available stock.");
    }
}
