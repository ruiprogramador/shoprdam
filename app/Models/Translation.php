<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Translation extends Model
{
    use SoftDeletes;

    protected $table = 'translations';

    protected $fillable = [
        'locale',
        'group',
        'key',
        'text_short',
        'text',
        'text_long',
        'text_html',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    // ─── Relations ───────────────────────────────────────────────
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'deleted_by');
    }

    // ─── Scopes ──────────────────────────────────────────────────
    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }

    public function scopeForGroup($query, string $group)
    {
        return $query->where('group', $group);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Get translations for __() compatibility.
     * Returns text as the default value.
     */
    public static function getForLocale(string $locale): array
    {
        return static::forLocale($locale)
            ->get()
            ->groupBy('group')
            ->map(fn ($items) =>
                $items->pluck('text', 'key')
            )
            ->toArray();
    }

    /**
     * Get full translation data for Vue/Inertia.
     * Includes all text variants.
     */
    public static function getFullForLocale(string $locale): array
    {
        return static::forLocale($locale)
            ->get(['group', 'key', 'text_short', 'text', 'text_long'])
            ->groupBy('group')
            ->map(fn ($items) =>
                $items->keyBy('key')->map(fn ($item) => [
                    'short' => $item->text_short,
                    'text'  => $item->text,
                    'long'  => $item->text_long,
                ])
            )
            ->toArray();
    }

    public static function availableLocales(): array
    {
        return static::distinct()->pluck('locale')->sort()->values()->toArray();
    }

    public static function availableGroups(): array
    {
        return static::distinct()->pluck('group')->sort()->values()->toArray();
    }
}