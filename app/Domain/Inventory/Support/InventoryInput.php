<?php

namespace App\Domain\Inventory\Support;

use App\Domain\Inventory\Exceptions\InvalidInventoryOperationException;

/**
 * Pure validation and identity grammar of control operations
 * (docs/inventory/INVENTORY-CONTROL.md §6.0 and §9.2). Touches no database.
 *
 * Quantities are checked with `is_int`, so they must reach here untyped: an `int`
 * parameter would let PHP coerce `"5"`, `2.0` or `true` before anything could
 * refuse them. Input is refused, never normalized.
 */
final class InventoryInput
{
    /** Operator-entered quantity ceiling: the portable ceiling of `order_items.quantity`. */
    public const MAX_OPERATION_QUANTITY = 2147483647;

    public const MAX_NOTE_LENGTH = 2000;

    private const CLIENT_KEY_GRAMMAR = '/^[a-z0-9][a-z0-9_-]{0,63}\z/';

    public static function operationQuantity(mixed $quantity): int
    {
        if (! is_int($quantity) || $quantity < 1 || $quantity > self::MAX_OPERATION_QUANTITY) {
            throw InvalidInventoryOperationException::invalidQuantity($quantity);
        }

        return $quantity;
    }

    /** A count's snapshot or observation: zero is a real value, the ceiling is the column's. */
    public static function countQuantity(mixed $quantity): int
    {
        if (! is_int($quantity) || $quantity < 0) {
            throw InvalidInventoryOperationException::invalidQuantity($quantity);
        }

        return $quantity;
    }

    public static function note(string $note): string
    {
        if (trim($note) === '' || ! mb_check_encoding($note, 'UTF-8') || mb_strlen($note, 'UTF-8') > self::MAX_NOTE_LENGTH) {
            throw InvalidInventoryOperationException::invalidNote();
        }

        return $note;
    }

    /** Caller keys cannot contain `:`, so no caller can forge a system identity below. */
    public static function clientKey(string $key): string
    {
        if (preg_match(self::CLIENT_KEY_GRAMMAR, $key) !== 1) {
            throw InvalidInventoryOperationException::invalidKey();
        }

        return 'client:'.$key;
    }

    public static function saleKey(int $reservationId): string
    {
        return 'sale:reservation:'.$reservationId;
    }

    public static function correctionKey(int $movementId): string
    {
        return 'correction:movement:'.$movementId;
    }

    public static function countAdjustmentKey(int $countId): string
    {
        return 'count:'.$countId;
    }
}
