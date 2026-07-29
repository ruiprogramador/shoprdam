<?php

namespace App\Models;

use App\Traits\HasActiveScope;
use App\Traits\HasOrderedScope;
use App\Traits\HasSlugLookup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderStatus extends Model
{
    use HasActiveScope, HasOrderedScope, HasSlugLookup;

    protected $fillable = ['name', 'slug', 'is_active', 'description', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
