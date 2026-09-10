<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Payments\Enums\RecoveryOutcome;
use Throwable;

/**
 * What App\Domain\Payments\Services\PaymentAttemptRecoveryService::recover()
 * returns — carries exactly the context each caller needs to reproduce its
 * own log/print/audit text, without either caller re-deriving anything
 * (age/retryability/attempt counts) the service already computed. Never
 * carries a raw provider payload or secret — $exception is whichever
 * exception PaymentService::finalizeAttempt() threw, and its ->getMessage()
 * is the same text already persisted to payment_attempts.last_recovery_error.
 */
final readonly class RecoveryResult
{
    public function __construct(
        public RecoveryOutcome $outcome,
        public ?int $ageMinutes = null,
        public ?int $maxAge = null,
        public ?Throwable $exception = null,
        public ?bool $retryable = null,
        public ?int $recoveryAttempts = null,
        public ?int $maxAttempts = null,
    ) {}
}
