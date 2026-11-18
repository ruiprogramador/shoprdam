<?php

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Exceptions\InvalidInventoryOperationException;

/**
 * The claimed actor of a control operation: a User id, which is also what the
 * evidence snapshots (no name, no email, no foreign key —
 * docs/inventory/INVENTORY-CONTROL.md §4.5). Constructing one proves nothing:
 * the canonical services re-read `products.store_id -> stores.user_id` and refuse
 * anyone but the owner.
 */
final readonly class StoreOwnerActor
{
    public function __construct(public int $userId)
    {
        if ($userId < 1) {
            throw InvalidInventoryOperationException::invalidActor($userId);
        }
    }
}
