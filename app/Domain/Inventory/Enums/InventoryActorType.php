<?php

namespace App\Domain\Inventory\Enums;

/**
 * Who caused a piece of stock evidence (docs/inventory/INVENTORY-CONTROL.md §4.5).
 * Not a role system: there is no RBAC here and an Admin is not a stock operator.
 */
enum InventoryActorType: string
{
    /** The owner of the Store owning the Inventory's Product; `actor_user_id` is set. */
    case StoreOwner = 'store_owner';

    /** The system: a `sale`, correlated through its reservation; `actor_user_id` is null. */
    case System = 'system';
}
