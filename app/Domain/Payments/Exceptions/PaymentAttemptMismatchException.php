<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * Thrown when a remote payment about to be claimed for a PaymentAttempt (in
 * PaymentService::startOrResumeAttempt()) doesn't actually match that
 * attempt's Payment — amount, currency, or correlation id. See
 * PaymentService::assertResultMatchesAttempt(). Never retryable: retrying
 * re-sends the same idempotency key, so it would just get the same
 * mismatched result back. Always surfaces as `needs_attention` via
 * App\Console\Commands\ReconcileOrphanedPaymentAttempts.
 */
class PaymentAttemptMismatchException extends RuntimeException {}
