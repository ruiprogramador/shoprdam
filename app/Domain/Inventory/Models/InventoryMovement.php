<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Read model of one change of `inventories.on_hand_quantity` — see
 * docs/inventory/INVENTORY-CONTROL.md §4.2 and §5. Evidence, never the source of
 * truth: nothing sums these rows to decide availability.
 *
 * Append-only. Rows are written by
 * App\Domain\Inventory\Services\InventoryMovementWriter through the query
 * builder, in the same transaction as the change they evidence. This model
 * refuses every Eloquent write on the `perform*` methods, not model events, which
 * `saveQuietly()` and `withoutEvents()` suppress. A wrong movement is compensated
 * by a new correction row, never edited or deleted. What these guards cannot see
 * is a write that never touches a model instance: covered statically by
 * tests/Architecture/InventoryBoundaryTest and detected by the integrity checker.
 */
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    /** No mass-assignment surface. */
    protected $fillable = [];

    protected $casts = [
        'reason' => InventoryMovementReason::class,
        'quantity_delta' => 'integer',
        'actor_type' => InventoryActorType::class,
        'actor_user_id' => 'integer',
        'after_baseline' => 'boolean',
    ];

    protected function performInsert(Builder $query)
    {
        throw new LogicException('InventoryMovement cannot be inserted through Eloquent; movements are written only by the canonical movement writer.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('InventoryMovement is append-only; a wrong movement is compensated, never edited.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('InventoryMovement is append-only and can never be incremented or decremented.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('InventoryMovement is stock evidence and can never be deleted.');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'reservation_id');
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'count_id');
    }

    public function corrects(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_movement_id');
    }
}
