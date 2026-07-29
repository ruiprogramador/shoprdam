<?php

namespace App\Models;

use App\Enums\TransactionDirection;
use App\Traits\HasActiveScope;
use App\Traits\HasOrderedScope;
use App\Traits\HasSlugLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionCategory extends Model
{
    use HasActiveScope, HasOrderedScope, HasSlugLookup;

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
