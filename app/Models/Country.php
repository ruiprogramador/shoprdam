<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    protected $table = 'countries';

    protected $fillable = [
        'name',
        'iso2',
        'iso3',
        'phone_code',
        'region',
        'subregion',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    /**
     * Relations
     */
    public function states(): HasMany
    {
        return $this->hasMany(State::class, 'country_id');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'country_id');
    }

    public function kycs(): HasMany
    {
        return $this->hasMany(Kyc::class, 'country_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    /**
     * Helpers
     */
    public static function findByIso2($iso2): ?self
    {
        return static::where('iso2', strtoupper($iso2))->first();
    }

    public static function findByIso3($iso3): ?self
    {
        return static::where('iso3', strtoupper($iso3))->first();
    }

    public static function findByPhoneCode($phoneCode): ?self
    {
        return static::where('phone_code', $phoneCode)->first();
    }

    public function getFlagEmojiAttribute(): string
    {
        if (! $this->iso2 || strlen($this->iso2) !== 2) {
            return '🌐';
        }
 
        return implode('', array_map(
            fn (string $char) => mb_chr(0x1F1E6 + ord($char) - ord('A')),
            str_split(strtoupper($this->iso2))
        ));
    }
}
