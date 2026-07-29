<?php

namespace App\Models;

use App\Enums\TransactionSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StoreWalletTransaction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'store_wallet_id',
        'transaction_category_id',
        'transaction_status_id',
        'amount',
        'balance_after',
        'description',
        'external_provider',
        'external_reference',
        'referenceable_type',
        'referenceable_id',
        'related_transaction_id',
        'metadata',
        'source',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'metadata' => 'array',
        'source' => TransactionSource::class,
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function storeWallet(): BelongsTo
    {
        return $this->belongsTo(StoreWallet::class, 'store_wallet_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TransactionStatus::class, 'transaction_status_id');
    }

    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_transaction_id');
    }

    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function childTransactions(): HasMany
    {
        return $this->hasMany(self::class, 'related_transaction_id');
    }

    public function isReversal(): bool
    {
        return ! is_null($this->related_transaction_id);
    }

    public function isCompleted(): bool
    {
        return $this->status?->slug === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status?->slug === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status?->slug === 'failed';
    }

    public function isReversed(): bool
    {
        return $this->status?->slug === 'reversed';
    }

    public function isCancelled(): bool
    {
        return $this->status?->slug === 'cancelled';
    }

    public function isCustomerRefund(): bool
    {
        return $this->category?->slug === 'customer_refund';
    }
}
