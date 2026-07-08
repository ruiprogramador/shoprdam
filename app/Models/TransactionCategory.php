<?php

namespace App\Models;

use App\Enums\TransactionDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class TransactionCategory extends Model
{
    protected $fillable = ['name', 'slug', 'direction', 'is_active', 'description'];

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

    public function isCredit(): bool
    {
        return $this->direction === TransactionDirection::Credit;
    }

    public function isDebit(): bool
    {
        return $this->direction === TransactionDirection::Debit;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}