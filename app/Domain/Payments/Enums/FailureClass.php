<?php

namespace App\Domain\Payments\Enums;

/**
 * Whether retrying a failed provider call stands any chance of succeeding —
 * see App\Domain\Payments\Contracts\PaymentProviderContract::classifyFailure().
 * Each provider adapter owns this classification for its own SDK/HTTP
 * exceptions (e.g. App\Payments\Stripe\StripePaymentProvider knows
 * Stripe\Exception\RateLimitException is transient); the generic
 * reconciler only ever asks the resolved provider, never inspects a
 * provider SDK exception class itself.
 */
enum FailureClass
{
    case Retryable;
    case NonRetryable;
}
