<?php

namespace App\Models;

use App\Domain\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;
use Nnjeim\World\Models\Currency;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'currency_id',
        'order_status_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_line_backed' => 'boolean',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    /**
     * Runtime half of the Order-lifecycle boundary (ORDER-01, see
     * docs/financial/ORDER-LIFECYCLE.md): any Eloquent save of an existing
     * Order that changes `order_status_id` is refused outright.
     *
     * Deliberately guards performUpdate(), not the `updating` model event:
     * `saveQuietly()`, `updateQuietly()` and `Order::withoutEvents(...)` all
     * suppress model events but still go through performUpdate(), so they
     * cannot slip past it — nor can `save()`, `update()`, `push()`, or an
     * `associate()`d status followed by any of those.
     *
     * The one sanctioned status write is
     * App\Domain\Orders\Services\OrderLifecycleService's compare-and-set
     * query-builder UPDATE, which never calls performUpdate() and so is
     * unaffected. What this cannot see is a write that never touches a model
     * instance (a query-builder/raw-SQL UPDATE elsewhere) — that is covered
     * statically by tests/Architecture/OrderLifecycleBoundaryTest.
     * (`creating` is deliberately not guarded — canonical line-backed creation
     * is App\Domain\Orders\Services\OrderCreationService; the one dev tool and
     * the factories still build legacy line-less Orders directly. See
     * ORDER-LIFECYCLE.md §8 and docs/orders/ORDER-ITEMS.md.)
     *
     * Second guard (ORDER-ITEM-08/19): an Order whose persisted provenance is
     * `is_line_backed` has a commercial aggregate — `store_id`, `currency_id`,
     * `amount` — derived from its immutable OrderItems, and may no longer be
     * changed through Eloquent; and the provenance flag itself never changes
     * through Eloquent for any Order. The guard keys on that stable flag, NOT on
     * whether lines currently exist, so it does not disappear if the lines are
     * removed by a raw write. Legacy Orders are deliberately unaffected. A
     * query-builder/raw-SQL UPDATE never reaches this method: that is covered
     * statically (tests/Architecture/OrderItemBoundaryTest) and, at payment
     * time, by OrderLineIntegrityChecker's fail-closed check.
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty('order_status_id')) {
            throw new LogicException(
                'Order status can only change through App\Domain\Orders\Services\OrderLifecycleService.'
            );
        }

        if ($this->exists && $this->isDirty('is_line_backed')) {
            throw new LogicException('Order provenance (is_line_backed) is set once at creation and can never change.');
        }

        if ($this->exists && $this->isDirty(['store_id', 'currency_id', 'amount']) && $this->persistedAsLineBacked()) {
            throw new LogicException(
                'store_id, currency_id and amount of a line-backed Order are derived from its immutable OrderItems and cannot be changed.'
            );
        }

        return parent::performUpdate($query);
    }

    /**
     * The PERSISTED provenance, never the in-memory (possibly dirty) value. If
     * this instance was loaded without the column, the database is asked
     * instead of assuming legacy — an unloaded flag must never read as false.
     */
    private function persistedAsLineBacked(): bool
    {
        if (array_key_exists('is_line_backed', $this->original)) {
            return (bool) $this->original['is_line_backed'];
        }

        return (bool) static::query()->whereKey($this->getKey())->value('is_line_backed');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** Empty for a legacy Order; see docs/orders/ORDER-ITEMS.md §6. */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'order_status_id');
    }

    public function walletTransactions(): MorphMany
    {
        return $this->morphMany(StoreWalletTransaction::class, 'referenceable');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status?->slug === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status?->slug === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status?->slug === 'failed';
    }

    public function isRefunded(): bool
    {
        return $this->status?->slug === 'refunded';
    }
}
