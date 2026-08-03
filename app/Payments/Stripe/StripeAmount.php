<?php

namespace App\Payments\Stripe;

/**
 * Converts a Wallet decimal amount into Stripe's minor-unit integer.
 *
 * Assumes a 2-decimal currency (`* 100`). Every Wallet amount column is
 * `decimal(_, 2)`, so this holds for every currency the domain currently
 * supports. The `currencies` table has a real `precision` column for
 * zero-decimal currencies like JPY, but nothing in the Wallet schema
 * honors it yet — fixing that is a schema-level decision, not something to
 * patch here. Centralized so the assumption lives in exactly one place
 * instead of being duplicated across every Stripe call site.
 */
final class StripeAmount
{
    public static function toMinorUnits(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }
}
