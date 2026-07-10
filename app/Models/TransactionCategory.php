<?php

namespace App\Models;

use App\Enums\TransactionDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasSlugLookup;
use App\Traits\HasActiveScope;
use App\Traits\HasOrderedScope;

class TransactionCategory extends Model
{
    use HasSlugLookup, HasActiveScope, HasOrderedScope;

    protected $fillable = ['name', 'slug', 'direction', 'sort_order', 'is_active', 'description'];

    protected $casts = [
        'is_active' => 'boolean',
        'direction' => TransactionDirection::class,
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(StoreWalletTransaction::class);
    }

    public function isCredit(): bool
    {
        return $this->direction === TransactionDirection::Credit;
    }

    public function isDebit(): bool
    {
        return $this->direction === TransactionDirection::Debit;
    }
}