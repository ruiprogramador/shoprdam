<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * The same idempotency identity was presented with a semantically different
 * request. Nothing was changed: an identity names one operation, so a differing
 * payload is refused, never merged or applied as a second operation.
 */
class InventoryIdempotencyConflictException extends RuntimeException
{
    public static function forKey(int $inventoryId, string $key): self
    {
        return new self("Inventory #{$inventoryId}: identity '{$key}' was already used by a different request.");
    }

    public static function forAdoption(int $inventoryId): self
    {
        return new self("Inventory #{$inventoryId} was already adopted by a different request.");
    }
}
