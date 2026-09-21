<?php

namespace App\Domain\Orders\Exceptions;

use InvalidArgumentException;

/**
 * App\Domain\Orders\Services\OrderCreationService refused to create an Order.
 * Always thrown before anything is committed: no Order, no OrderItem.
 */
class InvalidOrderCreationException extends InvalidArgumentException
{
    public static function noLines(): self
    {
        return new self('An Order must contain at least one line.');
    }

    public static function malformedLine(int|string $index, string $reason): self
    {
        return new self("Order line #{$index} is malformed: {$reason}");
    }

    public static function invalidQuantity(int|string $index, mixed $quantity): self
    {
        $printable = is_scalar($quantity) ? var_export($quantity, true) : get_debug_type($quantity);

        return new self("Order line #{$index}: quantity must be a positive integer, got {$printable}.");
    }

    public static function quantityOverflow(int $productId): self
    {
        return new self("Total quantity requested for Product #{$productId} exceeds the supported maximum.");
    }

    public static function totalOverflow(): self
    {
        return new self('The Order total exceeds the maximum representable amount.');
    }

    public static function nonPositiveTotal(): self
    {
        return new self('The Order total must be greater than 0.00; free-order settlement is not supported.');
    }

    public static function storeNotPersisted(): self
    {
        return new self('Cannot create an Order for a Store that has not been persisted yet.');
    }

    public static function productNotFound(int $productId): self
    {
        return new self("Product #{$productId} does not exist.");
    }

    public static function productDeleted(int $productId): self
    {
        return new self("Product #{$productId} has been deleted and cannot be ordered.");
    }

    public static function productInactive(int $productId): self
    {
        return new self("Product #{$productId} is not active and cannot be ordered.");
    }

    public static function productWrongStore(int $productId, int $storeId): self
    {
        return new self("Product #{$productId} does not belong to Store #{$storeId}.");
    }

    public static function malformedProduct(int $productId, string $reason): self
    {
        return new self("Product #{$productId} cannot be snapshotted: {$reason}");
    }

    public static function mixedCurrencies(): self
    {
        return new self('All Products in one Order must share one currency; no conversion is performed.');
    }
}
