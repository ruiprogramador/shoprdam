<?php

namespace App\Domain\Orders\Exceptions;

use RuntimeException;

/**
 * The compare-and-set write matched zero rows: something changed the Order's
 * status between this transition's locked read and its write. Under a real
 * row lock (MySQL/PostgreSQL `SELECT ... FOR UPDATE`) this cannot happen; it
 * exists as the portable backstop for drivers where the lock is a no-op
 * (SQLite). Fail-closed and safe to retry from scratch — nothing was written.
 */
class OrderTransitionConflictException extends RuntimeException
{
    public static function forOrder(int $orderId): self
    {
        return new self("Order #{$orderId} changed state concurrently; the transition was not applied.");
    }
}
