<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * Thrown from ReconciliationFindingRepository::openOrUpdateEpisode() in a
 * genuinely rare, but not impossible, window: the savepoint-protected
 * create() lost the unique(active_identity) race against an existing open
 * episode for this identity, but by the time this method re-read it, that
 * episode had already been resolved (resolveIfOpen() clears
 * `active_identity` back to null in the same write that sets
 * `resolved_at`). Unlike a Wallet ledger row, a
 * ReconciliationFinding's `active_identity` genuinely can be cleared this
 * way, so this is not a state-contradiction the way
 * WalletIdempotencyRaceUnresolvedException is — it is a real, if
 * vanishingly rare, race between two reconciliation passes for the same
 * identity. This domain is purely observational (never a financial ledger —
 * see docs/financial/RECONCILIATION.md §4/§14), so failing closed and
 * letting the next reconciliation pass re-observe fresh state is the safe
 * choice, rather than retrying inline.
 */
class ReconciliationEpisodeRaceUnresolvedException extends RuntimeException
{
    public static function forIdentity(string $activeIdentity): self
    {
        return new self(
            "Lost the unique(active_identity) race for '{$activeIdentity}' but the conflicting episode ".
            'was already resolved by the time it was re-read; a later reconciliation pass will re-observe it.'
        );
    }
}
