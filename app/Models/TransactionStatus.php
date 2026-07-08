<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class TransactionStatus extends Model
{
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

    public static function bySlug(string $slug): ?static
    {
        return static::query()
            ->where('slug', $slug)
            ->first();
    }

    public static function bySlugOrFail(string $slug): static
    {
        return static::query()
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}