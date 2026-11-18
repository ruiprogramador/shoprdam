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

    public static function inventoryVanished(int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId} no longer exists.");
    }

    public static function duplicateSaleEvidence(int $reservationId, int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId} already has sale evidence for reservation #{$reservationId}, which has just been committed; its status must have been rewritten.");
    }

    public static function countCasFailedUnderLock(int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId} no longer matches the locked row that decided this count.");
    }

    public static function baselineFlagCasFailedUnderLock(int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId} could not be marked as baselined under its own lock.");
    }

    public static function baselineFlagMismatch(int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId}: is_baselined and its baseline row disagree.");
    }

    public static function unknownStatus(int $reservationId, mixed $status): self
    {
        $printable = is_scalar($status) ? (string) $status : get_debug_type($status);

        return new self("Reservation #{$reservationId} has an unknown status '{$printable}'.");
    }
}
