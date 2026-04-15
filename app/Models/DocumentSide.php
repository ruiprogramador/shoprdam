<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSide extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relations
     */
    public function documents()
    {
        return $this->hasMany(KycDocument::class, 'document_side_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
