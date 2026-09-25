<?php

namespace App\Domain\Wallet\Exceptions;

/**
 * Thrown from WalletTransactionService::record() only in a state that should
 * be unreachable: the savepoint-protected create() lost the
 * unique(external_provider, external_reference) race (a real
 * QueryException, classified by isReferenceUniqueViolation()), but
 * re-reading the row it conflicted against found nothing. Since
 * StoreWalletTransaction rows are never deleted (immutable historical
 * ledger — see that model's own docblock), the row an insert conflicts
 * against cannot later vanish; hitting this means the local data is
 * inconsistent with the ledger it's supposed to describe. Failing closed
 * here rather than guessing at a wallet-balance effect no confirmed row
 * backs.
 */
class WalletIdempotencyRaceUnresolvedException extends WalletTransactionException
{
    public static function forReference(string $provider, string $reference): self
    {
        return new self(
            "Lost the idempotency race for reference '{$provider}:{$reference}' but no existing ".
            'transaction was found on re-read — the row this insert conflicted with is missing.'
        );
    }
}
