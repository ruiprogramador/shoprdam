<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * A reservation was refused because the atomic conditional update found
 * `on_hand_quantity - reserved_quantity < requested` at the moment it ran.
 * A normal, expected business outcome — not corruption. Thrown from inside
 * the reservation transaction, so it rolls back every line already reserved
 * for the same Order.
 */
class InsufficientStockException extends RuntimeException
{
    public static function forProduct(int $productId, int $requested): self
    {
        return new self("Insufficient available stock for Product #{$productId} (requested {$requested}).");
    }
}
