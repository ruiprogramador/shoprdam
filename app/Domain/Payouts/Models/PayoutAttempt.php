<?php

namespace App\Domain\Payouts\Models;

use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to execute a Payout through a specific provider. Durable
 * record, written before the provider is ever contacted (see
 * App\Domain\Payouts\Services\PayoutService::createDurableAttempt()) — the
 * same crash-window rationale as PaymentAttempt.
 *
 * Many attempts may exist per Payout over time, but at most one may be
 * non-terminal (see PayoutAttemptStatus::blocksNewAttempt()) at once —
 * enforced via Payout::$current_payout_attempt_id under a row lock, not a
 * column constraint on this table (mirrors payment_attempts exactly).
 *
 * `provider_reference` is a technical correlation id only.
 * `external_transfer_reference` is the real bank/SEPA reference proving a
 * transfer happened — never conflate the two; see each column's own
 * migration comment.
 */
class PayoutAttempt extends Model
{
    protected $fillable = [
        'payout_id',
        'provider',
        'provider_reference',
        'external_transfer_reference',
        'idempotency_key',
        'status',
        'locked_until',
        'recovery_attempts',
        'last_attempted_at',
        'last_recovery_error',
    ];

    protected $casts = [
        'status' => PayoutAttemptStatus::class,
        'locked_until' => 'datetime',
        'last_attempted_at' => 'datetime',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    /** Audit trail of recovery/manual-confirmation actions — see PayoutRecoveryAction. */
    public function recoveryActions(): HasMany
    {
        return $this->hasMany(PayoutRecoveryAction::class)->orderBy('created_at', 'desc');
    }
}
