<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Store extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'store_status_id',
        'slug',
        'name',
        'logo',
        'banner',
        'phone',
        'email',
        'short_description',
        'long_description',
        'is_featured',
        'published_at',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'is_featured'  => 'boolean',
        'published_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StoreStatus::class, 'store_status_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(StoreWallet::class);
    }

    // Accessors
    public function getLogoUrlAttribute(): string
    {
        return $this->logo
            ? Storage::disk('public')->url($this->logo)
            : asset('images/default-store-logo.png');
    }

    public function getBannerUrlAttribute(): string
    {
        return $this->banner
            ? Storage::disk('public')->url($this->banner)
            : asset('images/default-store-banner.png');
    }

    // Helpers
    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}