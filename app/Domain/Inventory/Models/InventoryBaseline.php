<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryBaselineKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Read model of where one Inventory's evidence starts — see
 * docs/inventory/INVENTORY-CONTROL.md §4.4 and §8. At most one per Inventory,
 * written once by App\Domain\Inventory\Services\InventoryControlService in the
 * same transaction as `inventories.is_baselined`. Every Eloquent write path is
 * refused on the `perform*` methods.
 */
class InventoryBaseline extends Model
{
    public const UPDATED_AT = null;

    /** No mass-assignment surface. */
    protected $fillable = [];

    protected $casts = [
        'kind' => InventoryBaselineKind::class,
        'on_hand_at_baseline' => 'integer',
        'reserved_at_baseline' => 'integer',
        'actor_type' => InventoryActorType::class,
        'actor_user_id' => 'integer',
    ];

    protected function performInsert(Builder $query)
    {
        throw new LogicException('InventoryBaseline cannot be inserted through Eloquent; baselines are written only by the canonical control service.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('InventoryBaseline is written once and can never be updated.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('InventoryBaseline can never be incremented or decremented.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('InventoryBaseline is stock evidence and can never be deleted.');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }
}
