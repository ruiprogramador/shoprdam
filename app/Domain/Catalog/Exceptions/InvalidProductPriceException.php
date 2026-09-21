<?php

namespace App\Domain\Catalog\Exceptions;

use InvalidArgumentException;

/**
 * A price string rejected by App\Domain\Catalog\Services\ProductService's
 * own validation, before anything is written. See that class's own docblock
 * for the exact accepted representation and every scenario named in
 * docs/catalog/CATALOG-DOMAIN.md's price-correctness section.
 */
class InvalidProductPriceException extends InvalidArgumentException
{
    public static function malformed(mixed $priceAmount): self
    {
        return new self(
            "Product price must be a plain decimal string with at most 2 decimal places (e.g. '19.99' or '20'). ".
            "Got: '".self::printable($priceAmount)."'.",
        );
    }

    public static function negative(mixed $priceAmount): self
    {
        return new self("Product price must not be negative. Got: '".self::printable($priceAmount)."'.");
    }

    /** A value's original, printable form for an error message — mirrors App\Domain\Payments\ConfigInteger::printable(). */
    private static function printable(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
