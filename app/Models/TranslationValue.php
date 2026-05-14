<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationValue extends Model
{
    protected $fillable = [
        'translation_id',
        'locale',
        'value_short',
        'value',
        'value_long',
        'value_html',
        'status',
        'updated_by',
        'translated_at',
    ];

    protected $casts = [
        'translated_at' => 'datetime',
    ];

    // ─── Relations ───────────────────────────────────────────────

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class, 'translation_id');
    }

    // Só existe updated_by na migration — creator() foi removido
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }

    // ─── Scopes ──────────────────────────────────────────────────

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', $locale);
    }

    public function scopeForStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}