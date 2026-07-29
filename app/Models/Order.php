<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
    ];

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
