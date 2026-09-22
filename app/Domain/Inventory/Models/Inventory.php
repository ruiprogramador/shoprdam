<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Read model of one Product's stock — see docs/inventory/INVENTORY-RESERVATIONS.md.
 * `on_hand_quantity` is physical units not yet consumed, `reserved_quantity`
 * is the units held by live reservations, and available stock is DERIVED
 * (`available()`), never stored.
 *
 * Every write to `inventories` goes through the atomic, conditional
 * statements in App\Domain\Inventory\Services\InventoryReservationService via
 * the query builder. This model therefore refuses every Eloquent INSTANCE
 * write path outright (`performInsert`/`performUpdate`/`performDeleteOnModel`
 * and `incrementOrDecrement`), so no `save()`, `update()`, `increment()`,
 * `decrement()` or `delete()` on an instance can read a stale number and write
 * it back. What these guards cannot see is a write that never touches a model
 * instance (`Inventory::query()->update()`, `DB::table(...)`, raw SQL,
 * tinker/psql) — covered statically by tests/Architecture/InventoryBoundaryTest
 * and, for safety, by the database CHECKs on the counters; not at all against
 * direct database access.
 */
class Inventory extends Model
{
    protected $table = 'inventories';

    /** No mass-assignment surface. */
    protected $fillable = [];

    protected $casts = [
        'on_hand_quantity' => 'integer',
        'reserved_quantity' => 'integer',
    ];

    /** Derived, never stored: units that may still be reserved. */
    public function available(): int
    {
        return $this->on_hand_quantity - $this->reserved_quantity;
    }

    protected function performInsert(Builder $query)
    {
        throw new LogicException('Inventory cannot be inserted through Eloquent; stock is written only by the canonical inventory service.');
    }

    /**
     * `$inventory->increment()`/`decrement()` do NOT reach `performUpdate()`
     * (Eloquent issues a builder UPDATE directly), so they get their own
     * refusal — a bare counter bump would skip the availability predicate.
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Inventory counters cannot be incremented/decremented directly; use the canonical inventory service.');
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException('Inventory cannot be updated through Eloquent; a read-modify-write of stock is exactly what allows overselling.');
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException('Inventory is history-bearing and can never be deleted.');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }
}
