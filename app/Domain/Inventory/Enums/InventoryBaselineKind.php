<?php

namespace App\Domain\Inventory\Enums;

/**
 * How an Inventory's evidence started (docs/inventory/INVENTORY-CONTROL.md §8).
 * An Inventory with no baseline at all is unevidenced legacy: that is the absence
 * of a case here, never a case.
 */
enum InventoryBaselineKind: string
{
    /** Created canonically at 0/0: fully evidenced since creation. */
    case Genesis = 'genesis';

    /** A legacy row adopted as it stood: evidenced since adoption, and nothing is claimed before it. */
    case Adopted = 'adopted';
}
