<?php

namespace App\Domain\Payouts\DTOs;

use App\Domain\Payouts\Enums\RecoveryOutcome;
use Throwable;

/**
 * What App\Domain\Payouts\Services\PayoutAttemptRecoveryService::recover()
 * returns — mirrors App\Domain\Payments\DTOs\RecoveryResult exactly. Never
 * carries a raw provider payload or secret — $exception's ->getMessage() is
 * the same text already persisted to payout_attempts.last_recovery_error,
 * only ever rendered through App\Domain\Payments\RecoveryErrorFormatter.
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
