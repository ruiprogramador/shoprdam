<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to satisfy a Payment through a specific provider/method.
 * Durable record, written before the provider is ever contacted (see
 * PaymentService::startOrResumeAttempt()) — closes the window where the
 * provider creates a remote payment but the process dies (or the following
 * DB write fails) before any local record of that exists. See
 * App\Console\Commands\ReconcileOrphanedPaymentAttempts, which retries
 * stale `pending` attempts by re-issuing the same idempotent request.
 *
 * Many attempts may exist per Payment over time, but at most one may be
 * non-terminal (see PaymentAttemptStatus::blocksNewAttempt()) at once —
 * enforced via Payment::$current_payment_attempt_id, not a column
 * constraint on this table.
 */
class PaymentAttempt extends Model
{
    protected $fillable = [
        'payment_id',
        'provider',
        'method',
        'provider_reference',
        'idempotency_key',
        'status',
        'locked_until',
        'recovery_attempts',
        'last_attempted_at',
        'last_recovery_error',
    ];

    protected $casts = [
        'status' => PaymentAttemptStatus::class,
        'locked_until' => 'datetime',
        'last_attempted_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
