<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryCountStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Read model of one physical-count observation — see
 * docs/inventory/INVENTORY-CONTROL.md §4.3, §7 and §16. Implemented, NOT
 * activated: nothing in production submits a count (STOCK-22).
 *
 * Append-once. The single change a row can ever undergo is the compare-and-set
 * `discrepancy_open -> discrepancy_resolved`, issued through the query builder by
 * App\Domain\Inventory\Services\InventoryCountService. Every Eloquent write path
 * is refused on the `perform*` methods, so a status can never be assigned.
 */
class InventoryCount extends Model
{
    public const UPDATED_AT = null;

    /** No mass-assignment surface. */
    protected $fillable = [];

    protected $casts = [
        'expected_on_hand' => 'integer',
        'observed_quantity' => 'integer',
        'on_hand_at_submission' => 'integer',
        'reserved_at_submission' => 'integer',
        'status' => InventoryCountStatus::class,
        'resolved_at' => 'datetime',
        'actor_type' => InventoryActorType::class,
        'actor_user_id' => 'integer',
    ];

    protected function performInsert(Builder $query)
    {
        throw new LogicException('InventoryCount cannot be inserted through Eloquent; counts are written only by the canonical count service.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('InventoryCount cannot be updated through Eloquent; the only permitted change is the count service\'s compare-and-set.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('InventoryCount can never be incremented or decremented.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('InventoryCount is stock evidence and can never be deleted.');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resolved_by_count_id');
    }
}
