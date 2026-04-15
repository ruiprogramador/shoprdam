<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    protected $fillable = [
        'country_id',
        'name',
        'country_code',
    ];

    /**
     * Relations
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'state_id');
    }

    public function kycs(): HasMany
    {
        return $this->hasMany(Kyc::class, 'state_id');
    }

    /**
     * Scopes
     */
    public function scopeForCountry($query, int $countryId)
    {
        return $query->where('country_id', $countryId); 
    }
}
