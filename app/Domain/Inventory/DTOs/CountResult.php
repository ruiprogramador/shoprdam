<?php

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Models\InventoryCount;
use App\Domain\Inventory\Models\InventoryMovement;

/**
 * The stored outcome of a physical-count submission. `stale` and
 * `discrepancy_open` are outcomes, not failures: the observation is persisted
 * and a replay of the same key returns it. `$adjustment` exists only for an
 * applied count with a non-zero variance; `$resolvedCountIds` lists the
 * discrepancies this count superseded.
 */
final readonly class CountResult
{
    /** @param  list<int>  $resolvedCountIds */
    public function __construct(
        public InventoryCount $count,
        public ?InventoryMovement $adjustment,
        public array $resolvedCountIds,
        public bool $replayed,
    ) {}
}
