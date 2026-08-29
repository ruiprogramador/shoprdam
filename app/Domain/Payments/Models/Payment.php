<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The single financial obligation an Order has. One Payment per Order
 * (`order_id` unique), but a Payment may go through several PaymentAttempts
 * over time — one per provider/method try — see PaymentAttempt.
 *
 * Has no amount/currency columns of its own: always reads $this->order->amount
 * and $this->order->currency, the same way every attempt/retry re-derives
 * them fresh rather than trusting a stored snapshot that could drift from
 * the Order's own current values.
 */
class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'status',
        'current_payment_attempt_id',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function currentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'current_payment_attempt_id');
    }
}
