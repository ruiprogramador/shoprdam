<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\DTOs\StoreOwnerActor;
use App\Domain\Inventory\Exceptions\InvalidInventoryOperationException;
use App\Domain\Inventory\Models\Inventory;
use App\Models\Store;

/**
 * Resolves what a control operation acts on, and refuses anyone but the owner:
 * Inventory -> Product -> Store -> owner (docs/inventory/INVENTORY-CONTROL.md
 * §6.0). A domain precondition, not RBAC: an Admin has no stock capability here.
 *
 * Reads stored ownership only, never a caller's model attributes. A soft-deleted
 * Product or Store still has an owner, and stock is physical: discontinuing a
 * Product does not make its units vanish. Only createInventory() refuses a
 * soft-deleted Product.
 */
final class InventoryTarget
{
    public static function productId(Product|int $product): int
    {
        return (int) ($product instanceof Product ? $product->getKey() : $product);
    }

    /** @return bool whether the Product is soft-deleted */
    public static function assertOwner(int $productId, StoreOwnerActor $actor): bool
    {
        $row = Product::query()->withTrashed()->whereKey($productId)->toBase()->first(['store_id', 'deleted_at']);

        if ($row === null) {
            throw InvalidInventoryOperationException::productMissing($productId);
        }

        $owner = Store::query()->withTrashed()->whereKey($row->store_id)->toBase()->value('user_id');

        if ((int) $owner !== $actor->userId) {
            throw InvalidInventoryOperationException::notStoreOwner($productId, $actor->userId);
        }

        return $row->deleted_at !== null;
    }

    public static function inventoryId(int $productId): int
    {
        $id = Inventory::query()->where('product_id', $productId)->toBase()->value('id');

        if ($id === null) {
            throw InvalidInventoryOperationException::inventoryMissing($productId);
        }

        return (int) $id;
    }
}
