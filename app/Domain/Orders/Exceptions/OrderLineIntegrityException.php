<?php

namespace App\Domain\Orders\Exceptions;

use RuntimeException;

/**
 * A line-backed Order's stored aggregate (amount/currency) disagrees with its
 * own immutable OrderItem snapshots. Read-only detection — nothing is
 * repaired or rewritten; the caller must fail closed.
 */
class OrderLineIntegrityException extends RuntimeException
{
    public static function amountMismatch(int $orderId, string $stored, string $expected): self
    {
        return new self("Order #{$orderId} amount {$stored} does not equal the exact sum of its lines ({$expected}); refusing to proceed.");
    }

    /** Provenance says the Order was created with lines, but none exist: corrupt, never "legacy". */
    public static function lineBackedWithoutLines(int $orderId): self
    {
        return new self("Order #{$orderId} is line-backed but has no lines; refusing to proceed.");
    }

    /** Provenance says legacy, yet lines exist: the two facts contradict each other. */
    public static function legacyWithLines(int $orderId): self
    {
        return new self("Order #{$orderId} is a legacy Order but has lines; its provenance is inconsistent, refusing to proceed.");
    }

    public static function currencyMismatch(int $orderId, int $itemId): self
    {
        return new self("Order #{$orderId} line #{$itemId} uses a different currency than the Order; refusing to proceed.");
    }
}
