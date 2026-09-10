<?php

namespace App\Domain\Payments\Models;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One durable, auditable record of an admin manually triggering payment
 * recovery for a PaymentAttempt — written by
 * App\Http\Controllers\Admin\PaymentRecoveryController for every invocation,
 * successful or not (see that controller's own docblock). Never itself a
 * financial record and never read by any settlement/reconciliation path —
 * purely an audit trail, the payments-domain counterpart to `KycHistory`.
 */
class PaymentRecoveryAction extends Model
{
    protected $table = 'payment_recovery_actions';

    protected $fillable = [
        'payment_attempt_id',
        'admin_id',
        'action',
        'outcome',
        'detail',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'payment_attempt_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
