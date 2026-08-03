<?php

namespace App\Models;

use App\Enums\PaymentIntentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable record, written before the Stripe API call in
 * StripePaymentService::createPaymentIntentForOrder(), that a PaymentIntent
 * was about to be requested for an Order. Closes the window where Stripe
 * creates the PaymentIntent but the process dies (or the following DB write
 * fails) before any local record of that exists — see
 * App\Console\Commands\ReconcileOrphanedPaymentIntents, which retries stale
 * `pending` attempts by re-issuing the same idempotent request.
 */
class OrderPaymentIntentAttempt extends Model
{
    protected $fillable = [
        'order_id',
        'idempotency_key',
        'status',
        'recovery_attempts',
        'last_recovery_attempted_at',
        'last_recovery_error',
    ];

    protected $casts = [
        'status' => PaymentIntentAttemptStatus::class,
        'last_recovery_attempted_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
