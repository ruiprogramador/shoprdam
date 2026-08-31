<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * Thrown by PaymentService::startOrResumeAttempt() when asked to start a
 * new attempt for a Payment that is no longer `pending` (already `paid`,
 * `failed`, or `refunded`). Distinct from an in-flight attempt blocking a
 * new one (that returns the existing attempt instead of throwing — see
 * PaymentAttemptStatus::blocksNewAttempt()): this is the Payment itself
 * having nothing left to attempt.
 */
class PaymentAlreadyResolvedException extends RuntimeException {}
