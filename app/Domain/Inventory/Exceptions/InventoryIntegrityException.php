<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Stored inventory state contradicts itself (counters vs. reservations,
 * reservation vs. its OrderItem, a partially reserved Order, ...). The
 * operation fails closed and is rolled back; nothing is repaired or guessed.
 */
class InventoryIntegrityException extends RuntimeException
{
    public static function partiallyReserved(int $orderId): self
    {
        return new self("Order #{$orderId} is only partially reserved; refusing to complete or repeat the reservation.");
    }

    public static function reservationMismatch(int $reservationId, string $what): self
    {
        return new self("Reservation #{$reservationId} disagrees with its source: {$what}.");
    }

    public static function counterDrift(int $reservationId, int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId} counters cannot absorb the transition of reservation #{$reservationId}; counters have drifted from the reservation rows.");
    }

    public static function unknownStatus(int $reservationId, mixed $status): self
    {
        $printable = is_scalar($status) ? (string) $status : get_debug_type($status);

        return new self("Reservation #{$reservationId} has an unknown status '{$printable}'.");
    }
}
