<?php

namespace App\Models;

use App\Traits\HasActiveScope;
use App\Traits\HasOrderedScope;
use App\Traits\HasSlugLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionStatus extends Model
{
    use HasActiveScope, HasOrderedScope, HasSlugLookup;

    protected $fillable = ['name', 'slug', 'is_active', 'description', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(StoreWalletTransaction::class);
    }

    public function isPending(): bool
    {
        return $this->slug === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->slug === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->slug === 'failed';
    }
}
