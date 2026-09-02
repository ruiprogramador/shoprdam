<?php

namespace App\Payments\EasyPay\Exceptions;

use RuntimeException;

/**
 * The request never got a response at all (DNS failure, timeout, connection
 * reset) — EasyPay may or may not have actually processed it before the
 * network broke. Always Retryable: the request was sent under the attempt's
 * own deterministic Idempotency-Key, so retrying is safe regardless of
 * whether the first attempt actually landed. See
 * EasyPayPaymentProvider::classifyFailure() and the equivalent Stripe
 * ApiConnectionException handling this mirrors.
 */
class EasyPayConnectionException extends RuntimeException
{
}
