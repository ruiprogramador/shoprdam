<?php

namespace App\Domain\Payments;

/**
 * Converts a Wallet decimal amount into a provider's minor-unit integer.
 * Not provider-specific despite living next to Stripe historically
 * (App\Payments\Stripe\StripeAmount before this refactor) — every provider
 * this domain talks to takes integer minor units, so the conversion itself
 * belongs here, in the domain, not duplicated per adapter.
 *
 * Assumes a 2-decimal currency (`* 100`). Every Wallet amount column is
 * `decimal(_, 2)`, so this holds for every currency the domain currently
 * supports. The `currencies` table has a real `precision` column for
 * zero-decimal currencies like JPY, but nothing in the Wallet schema
 * honors it yet — fixing that is a schema-level decision, not something to
 * patch here.
 */
final class MinorUnits
{
    public static function fromDecimal(string $amount): int
    {
        return (int) bcmul($amount, '100', 0);
    }
}
