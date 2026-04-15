<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kyc extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'kyc_status_id',
        'reviewed_by',
        'country_id',
        'state_id',
        'city_id',
        'full_name',
        'date_of_birth',
        'gender_id',
        'gender_other',
        'address_line1',
        'address_line2',
        'postal_code',
        'rejection_reason',
        'reviewed_at',
        'expires_at',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'reviewed_at'   => 'datetime',
        'expires_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function kycStatus(): BelongsTo
    {
        return $this->belongsTo(KycStatus::class, 'kyc_status_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'kyc_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(KycHistory::class, 'kyc_id')->orderBy('created_at', 'asc');
    }

    public function scopePending($query)
    {
        return $query->whereHas('kycStatus', fn($q) => $q->where('slug', 'pending'));
    }

    public function scopeApproved($query)
    {
        return $query->whereHas('kycStatus', fn($q) => $q->where('slug', 'approved'));
    }

    public function scopeRejected($query)
    {
        return $query->whereHas('kycStatus', fn($q) => $q->where('slug', 'rejected'));
    }

    public function scopeExpiringSoon($query)
    {
        return $query->where('expires_at', '>=', now())->where('expires_at', '<=', now()->addDays(30));
    }

    // Helpers
    public function isPending()
    {
        return $this->kycStatus?->slug === 'pending';
    }

    public function isApproved()
    {
        return $this->kycStatus?->slug === 'approved';
    }

    public function isRejected()
    {
        return $this->kycStatus?->slug === 'rejected';
    }

    public function isExpiringSoon()
    {
        return $this->expires_at && $this->expires_at->isBetween(now(), now()->addDays(30));
    }

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function canBeReviewed()
    {
        return $this->isPending() || $this->isExpiringSoon();
    }

    public function canBeResubmitted()
    {
        return $this->isRejected() || $this->isExpired();
    }

    public function canBeEdited()
    {
        return $this->isPending() || $this->isRejected() || $this->isExpiringSoon() || $this->isExpired();
    }

    public function canBeDeleted()
    {
        return $this->isPending() || $this->isRejected() || $this->isExpiringSoon() || $this->isExpired();
    }

    public function canBeApproved()
    {
        return $this->isPending() || $this->isExpiringSoon();
    }

    public function canBeRejected()
    {
        return $this->isPending() || $this->isExpiringSoon();
    }

    public function canBeExpired()
    {
        return $this->isApproved() && $this->expires_at && $this->expires_at->isPast();
    }

    public function getGenderLabelAttribute()
    {
        if ($this->gender?->isCustom() && $this->gender_other) {
            return $this->gender_other;
        }
 
        return $this->gender?->name ?? '—';
    }

    /**
     * Transition to a new status and record in history.
     */
    public function transitionToStatus(KycStatus $newStatus, ?User $reviewer = null, ?string $notes = null)
    {
        $oldStatus = $this->kycStatus;

        $this->update([
            'kyc_status_id' => $newStatus->id,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
            'rejection_reason' => $newStatus->slug === 'rejected' ? $notes : $this->rejection_reason, // Only set reason if rejected, otherwise keep existing reason   
        ]);

        $this->history()->create([
            'from_status_id' => $oldStatus?->id,
            'to_status_id' => $newStatus->id,
            'reviewed_by' => $reviewer?->id,
            'notes' => $notes,
        ]);
    }
}
