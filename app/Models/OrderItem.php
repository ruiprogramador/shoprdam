<?php

namespace App\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Nnjeim\World\Models\Currency;

/**
 * The immutable historical commercial snapshot of one Product line inside one
 * Order — see docs/orders/ORDER-ITEMS.md. `product_name`,
 * `unit_price_amount`, `currency_id` and `quantity` are facts frozen at order
 * creation; `product_id` is traceability only and is never used to re-derive
 * them (there is deliberately no accessor that reads live Product values).
 *
 * Rows are written exactly once, by
 * App\Domain\Orders\Services\OrderCreationService, together with their Order
 * and every sibling line, in one transaction — and never again. This model
 * therefore refuses every Eloquent write path outright:
 *
 * - `performInsert()`  : no `create()`, `save()`, `saveQuietly()`,
 *   `$order->items()->create()` or factory — the service writes through the
 *   query builder instead, which this guard never sees;
 * - `performUpdate()`  : no `update()`, `save()`, `updateQuietly()`,
 *   `forceFill()->save()`, `touch()`, `associate()`+save, `withoutEvents()`;
 * - `performDeleteOnModel()` : no `delete()`, `deleteQuietly()`, `destroy()`.
 *
 * The guards sit on the `perform*` methods, not model events, for the same
 * reason Order and Product do: quiet saves and `withoutEvents()` suppress
 * events but still reach them. What they cannot see is a write that never
 * touches a model instance (`OrderItem::query()->update()`, `DB::table(...)`,
 * raw SQL, tinker/psql) — covered statically by
 * tests/Architecture/OrderItemBoundaryTest, and not at all against direct
 * database access.
 */
class OrderItem extends Model
{
    /** No mass-assignment surface: nothing here is meant to be filled by callers. */
    protected $fillable = [];

    protected $casts = [
        'unit_price_amount' => 'decimal:2',
        'quantity' => 'integer',
    ];

    protected function performInsert(Builder $query)
    {
        throw new LogicException(
            'OrderItem cannot be inserted through Eloquent. Lines are created only, and all at once, by '.
            'App\Domain\Orders\Services\OrderCreationService together with their Order.'
        );
    }

    protected function performUpdate(Builder $query)
    {
        throw new LogicException(
            'OrderItem is an immutable historical snapshot; it can never be updated once created.'
        );
    }

    protected function performDeleteOnModel()
    {
        throw new LogicException(
            'OrderItem is historical commercial evidence; it can never be deleted.'
        );
    }

    /**
     * Exact `unit_price_amount * quantity`, derived on demand from the two
     * immutable snapshot fields (BCMath, never float). Never stored: a stored
     * copy could only ever drift from its own inputs.
     */
    public function lineTotal(): string
    {
        return bcmul((string) $this->unit_price_amount, (string) $this->quantity, 2);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Traceability only — never a source of price/name/currency. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
