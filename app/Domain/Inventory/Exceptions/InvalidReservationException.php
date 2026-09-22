<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\InventoryReservationStatus;
use RuntimeException;

/**
 * The request itself cannot be honored: the Order is not eligible for
 * reservation, has no Inventory to reserve from, or the reservation is in a
 * state the requested transition may not leave. Nothing was changed.
 */
class InvalidReservationException extends RuntimeException
{
    public static function noLines(int $orderId): self
    {
        return new self("Order #{$orderId} has no lines to reserve. Legacy line-less Orders have no inventory reservation semantics.");
    }

    public static function inventoryMissing(int $productId): self
    {
        return new self("Product #{$productId} has no Inventory record; stock is never assumed to exist.");
    }

    public static function alreadyTerminal(int $orderItemId, InventoryReservationStatus $status): self
    {
        return new self("OrderItem #{$orderItemId} already has a {$status->value} reservation; a terminal reservation is never reserved again.");
    }

    public static function noReservations(int $orderId): self
    {
        return new self("Order #{$orderId} has no inventory reservations.");
    }

    public static function illegalTransition(int $reservationId, InventoryReservationStatus $from, InventoryReservationStatus $to): self
    {
        return new self("Reservation #{$reservationId} is {$from->value} and cannot become {$to->value}.");
    }
}
