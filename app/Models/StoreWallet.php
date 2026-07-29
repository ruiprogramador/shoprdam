<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Nnjeim\World\Models\Currency;

class StoreWallet extends Model
{
    protected $fillable = ['store_id', 'currency_id', 'balance', 'last_transaction_at'];

    protected $casts = [
        'balance' => 'decimal:2',
        'last_transaction_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(StoreWalletTransaction::class);
    }

    public function latestTransaction(): HasOne
    {
        return $this->hasOne(StoreWalletTransaction::class)
            ->latestOfMany();
    }
}
