<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Inventory\Enums\InventoryMovementReason;
use RuntimeException;

/**
 * The request itself cannot be honored: its input is malformed, its target does
 * not exist or does not correlate, the actor is not the owner, or the operation
 * is not defined for that target. Nothing was changed.
 */
class InvalidInventoryOperationException extends RuntimeException
{
    public static function invalidActor(int $userId): self
    {
        return new self("'{$userId}' is not a valid actor id.");
    }

    public static function invalidQuantity(mixed $quantity): self
    {
        $printable = is_scalar($quantity) ? var_export($quantity, true) : get_debug_type($quantity);

        return new self("{$printable} is not a valid stock quantity.");
    }

    public static function invalidKey(): self
    {
        return new self('An idempotency key must match ^[a-z0-9][a-z0-9_-]{0,63}$.');
    }

    public static function invalidNote(): self
    {
        return new self('The note must not be blank and must fit the permitted length.');
    }

    public static function productMissing(int $productId): self
    {
        return new self("Product #{$productId} does not exist.");
    }

    public static function productDeleted(int $productId): self
    {
        return new self("Product #{$productId} is deleted; no Inventory is created for it.");
    }

    public static function inventoryMissing(int $productId): self
    {
        return new self("Product #{$productId} has no Inventory; stock is never assumed to exist.");
    }

    public static function notStoreOwner(int $productId, int $userId): self
    {
        return new self("User #{$userId} does not own the Store of Product #{$productId}.");
    }

    public static function requiresAdoption(int $productId): self
    {
        return new self("Product #{$productId} has a legacy Inventory; it must be adopted, not created.");
    }

    public static function alreadyBaselined(int $productId): self
    {
        return new self("Product #{$productId} already has an Inventory that did not come from this operation.");
    }

    public static function movementMismatch(int $movementId, int $productId): self
    {
        return new self("Movement #{$movementId} is not a movement of Product #{$productId}'s Inventory.");
    }

    public static function notCorrectable(int $movementId, InventoryMovementReason $reason): self
    {
        return new self("Movement #{$movementId} is a {$reason->value}, which is never corrected.");
    }
}
