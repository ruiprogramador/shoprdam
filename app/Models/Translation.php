<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Translation extends Model
{
    protected $fillable = [
        'key',
        'label',
        'context',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    // ─── Relations ───────────────────────────────────────────────

    public function values(): HasMany
    {
        return $this->hasMany(TranslationValue::class, 'translation_id');
    }

    public function valueFor(string $locale): ?TranslationValue
    {
        // Assume que values já foi eager-loaded; caso contrário usa where()
        return $this->relationLoaded('values')
            ? $this->values->firstWhere('locale', $locale)
            : $this->values()->where('locale', $locale)->first();
    }

    // ─── Scopes ──────────────────────────────────────────────────

    // Filtra pela locale via join na tabela values
    public function scopeForLocale($query, string $locale)
    {
        return $query->whereHas('values', fn ($q) => $q->where('locale', $locale));
    }

    // Filtra por group (prefixo da key, ex: "auth")
    public function scopeForGroup($query, string $group)
    {
        return $query->where('key', 'like', "{$group}.%");
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Devolve as locales disponíveis a partir de translation_values.
     */
    public static function availableLocales(): array
    {
        return TranslationValue::distinct()
            ->orderBy('locale')
            ->pluck('locale')
            ->toArray();
    }

    /**
     * Devolve os grupos disponíveis (prefixo antes do primeiro '.' na key).
     */
    public static function availableGroups(): array
    {
        return static::selectRaw("SUBSTRING_INDEX(`key`, '.', 1) as grp")
            ->distinct()
            ->orderBy('grp')
            ->pluck('grp')
            ->toArray();
    }

    /**
     * Para o DatabaseLoader / __() — devolve [subkey => value].
     */
    public static function getForLocale(string $locale): array
    {
        return static::with(['values' => fn ($q) => $q->where('locale', $locale)])
            ->get()
            ->mapWithKeys(fn ($t) => [
                $t->key => $t->values->first()?->value ?? $t->key,
            ])
            ->toArray();
    }

    /**
     * Para o Vue/Inertia — devolve todos os variants por key.
     */
    public static function getFullForLocale(string $locale): array
    {
        return static::with(['values' => fn ($q) => $q->where('locale', $locale)])
            ->get()
            ->mapWithKeys(function ($t) {
                $v = $t->values->first();

                return [
                    $t->key => [
                        'short'  => $v?->value_short,
                        'text'   => $v?->value,
                        'long'   => $v?->value_long,
                        'html'   => $v?->value_html,
                        'status' => $v?->status ?? 'missing',
                    ],
                ];
            })
            ->toArray();
    }
}