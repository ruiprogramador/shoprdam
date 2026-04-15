<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = [
        'country_id',
        'state_id',
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

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function kycs(): HasMany
    {
        return $this->hasMany(Kyc::class, 'city_id');
    }

    /**
     * Scopes
     */
    public function scopeForCountry($query, int $countryId)
    {
        return $query->where('country_id', $countryId);
    }

    public function scopeForState($query, int $stateId)
    {
        return $query->where('state_id', $stateId);
    }
}
