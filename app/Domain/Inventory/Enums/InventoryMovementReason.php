<?php

namespace App\Domain\Inventory\Enums;

/**
 * Why `inventories.on_hand_quantity` changed — the closed vocabulary of
 * `inventory_movements.reason`, constrained by a database CHECK to exactly these
 * five (docs/inventory/INVENTORY-CONTROL.md §4.2).
 *
 * There is deliberately no restock case (physical returns are an open product
 * decision, §6.8) and no opening balance: a first increase is an ordinary
 * evidenced receipt or an initial stocktake (§8.1). Reservations are not
 * movements: `reserve`/`release` do not change `on_hand`.
 */
enum InventoryMovementReason: string
{
    /** Goods arrived. Always an increase. */
    case Receipt = 'receipt';

    /** A committed reservation consumed stock. Always a decrease; system-produced; never corrected. */
    case Sale = 'sale';

    /** Goods lost, damaged or broken, within `available`. Always a decrease. */
    case WriteOff = 'write_off';

    /** The effect of an `applied` physical count with a non-zero variance. Either sign. */
    case CountAdjustment = 'count_adjustment';

    /** The exact reversal of a mistaken receipt or write-off. Sign opposite to the original. */
    case Correction = 'correction';

    /**
     * A sale is never corrected (a committed reservation is terminal), a count
     * adjustment is corrected by a re-count, and a correction is final (§5).
     */
    public function isCorrectable(): bool
    {
        return match ($this) {
            self::Receipt, self::WriteOff => true,
            self::Sale, self::CountAdjustment, self::Correction => false,
        };
    }
}
