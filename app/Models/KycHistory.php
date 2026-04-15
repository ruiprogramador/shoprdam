<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycHistory extends Model
{
    protected $fillable = [
        'kyc_id',
        'reviewed_by',
        'from_status_id',
        'to_status_id',
        'notes',
    ];

    /**
     * Relations
     */
    public function kyc(): BelongsTo
    {
        return $this->belongsTo(Kyc::class, 'kyc_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(KycStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(KycStatus::class, 'to_status_id');
    }
}
