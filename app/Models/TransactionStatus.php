<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use App\Traits\HasSlugLookup;
use App\Traits\HasActiveScope;
use App\Traits\HasOrderedScope;

class TransactionStatus extends Model
{
    use HasSlugLookup, HasActiveScope, HasOrderedScope;

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
}