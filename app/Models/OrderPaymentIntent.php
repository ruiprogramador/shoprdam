<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The claim that ties an Order to the single Stripe PaymentIntent it is
 * allowed to have. The unique constraint on `order_id` is what actually
 * enforces the one-PaymentIntent-per-Order invariant — see
 * StripePaymentService::createPaymentIntentForOrder().
 */
class OrderPaymentIntent extends Model
{
    protected $fillable = [
        'order_id',
        'payment_intent_id',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
