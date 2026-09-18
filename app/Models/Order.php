<?php

namespace App\Models;

use App\Domain\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * (`creating` is deliberately not guarded — there is no production
     * Order-creation path yet, only a test tool and factories; see
     * ORDER-LIFECYCLE.md §8.)
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->isDirty('order_status_id')) {
            throw new LogicException(
                'Order status can only change through App\Domain\Orders\Services\OrderLifecycleService.'
            );
        }

        return parent::performUpdate($query);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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
