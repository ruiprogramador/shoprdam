<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycStatus extends Model
{
    protected $table = 'kyc_status';

    protected $fillable = [
        'name',
        'slug',
        'color',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relations
     */
    public function kycs()
    {
        return $this->hasMany(Kyc::class, 'kyc_status_id');
    }

    public function historyFrom()
    {
        return $this->hasMany(KycHistory::class, 'from_status_id');
    }

    public function historyTo()
    {
        return $this->hasMany(KycHistory::class, 'to_status_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Helpers
     */
    public static function findBySlug($slug): ?self
    {
        return static::where('slug', $slug)->first();
    }
}
