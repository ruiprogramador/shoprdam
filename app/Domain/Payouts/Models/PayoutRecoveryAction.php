<?php

namespace App\Domain\Payouts\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail for App\Http\Controllers\Admin\PayoutRecoveryController —
 * mirrors App\Domain\Payments\Models\PaymentRecoveryAction exactly. A row is
 * inserted with outcome `started` *before* any recovery/confirmation
 * operation runs, then updated once the real outcome is known — see that
 * controller's startAction()/finishAction(). Never updated a third time.
 */
class PayoutRecoveryAction extends Model
{
    protected $fillable = [
        'payout_attempt_id',
        'admin_id',
        'action',
        'outcome',
        'detail',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PayoutAttempt::class, 'payout_attempt_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
