<?php

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\InventoryMovementReason;

/**
 * Everything a movement carries besides its Inventory and its delta, which the
 * writer derives from the guarded statement it pairs the movement with.
 * `$afterBaseline` must be the value read under the current transaction's
 * Inventory lock.
 */
final readonly class MovementDraft
{
    public function __construct(
        public InventoryMovementReason $reason,
        public string $idempotencyKey,
        public int $actorUserId,
        public bool $afterBaseline,
        public ?string $note,
        public ?int $correctsMovementId = null,
    ) {}
}
