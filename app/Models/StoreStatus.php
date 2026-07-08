<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\HasSlugLookup;
use App\Traits\HasActiveScope;
use App\Traits\HasOrderedScope;

class StoreStatus extends Model
{
    use HasSlugLookup, HasActiveScope, HasOrderedScope;

    protected $fillable = ['name', 'slug', 'color', 'is_active', 'description', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}