<?php

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Models\InventoryMovement;

/**
 * What a movement-producing control operation did. `replayed === true` means an
 * identical earlier request already had this effect and nothing happened now: the
 * result says "your effect happened", not what the current stock is.
 */
final readonly class MovementResult
{
    public function __construct(
        public InventoryMovement $movement,
        public bool $replayed,
    ) {}
}
