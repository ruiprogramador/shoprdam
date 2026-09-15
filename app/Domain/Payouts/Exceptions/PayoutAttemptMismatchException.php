<?php

namespace App\Domain\Payouts\Exceptions;

use RuntimeException;

/**
 * Thrown when a provider's transfer result (or a manually-submitted
 * confirmation) doesn't actually match the PayoutAttempt/Payout it's being
 * applied to — amount, currency, or correlation id. Mirrors
 * App\Domain\Payments\Exceptions\PaymentAttemptMismatchException. Never
 * retryable: retrying resends the same idempotency key and would get the
 * same mismatched result back.
 */
class PayoutAttemptMismatchException extends RuntimeException {}
