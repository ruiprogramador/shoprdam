<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\InventoryReservationStatus;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Read model of one OrderItem's hold on one Inventory — see
 * docs/inventory/INVENTORY-RESERVATIONS.md. Its status changes only through
 * the named, compare-and-set transitions of
 * App\Domain\Inventory\Services\InventoryReservationService (query-builder
 * `UPDATE ... WHERE status = 'reserved'`); an arbitrary `status` assignment
 * is impossible through Eloquent because every write path is refused here,
 * on the `perform*` methods (not model events, which `saveQuietly()` and
 * `withoutEvents()` suppress). Terminal rows are evidence and are never
 * deleted.
 */
class InventoryReservation extends Model
{
    /** No mass-assignment surface. */
    protected $fillable = [];

    protected $casts = [
        'quantity' => 'integer',
        'status' => InventoryReservationStatus::class,
        'committed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    protected function performInsert(Builder $query)
    {
        throw new LogicException('InventoryReservation cannot be inserted through Eloquent; reservations are created only by the canonical inventory service.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('InventoryReservation cannot be updated through Eloquent; status changes only through the canonical inventory service.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('InventoryReservation cannot be incremented/decremented; it is changed only by the canonical inventory service.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('InventoryReservation is inventory evidence and can never be deleted.');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
