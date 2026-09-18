<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Payments\Enums\ReconciliationOutcomeType;
use App\Domain\Payments\Models\ReconciliationFinding;
use Throwable;

/**
 * What App\Domain\Payments\Services\ProviderReconciler::reconcile() did for
 * one PaymentAttempt — for the CLI/logging layer to render
 * (docs/financial/RECONCILIATION.md §15). Never itself a financial result.
 */
final readonly class ReconciliationOutcome
{
    private function __construct(
        public ReconciliationOutcomeType $type,
        public ?ReconciliationFinding $finding = null,
        public ?Throwable $exception = null,
        public ?bool $retryable = null,
        public ?string $reason = null,
    ) {}

    public static function observed(?ReconciliationFinding $finding): self
    {
        return new self(ReconciliationOutcomeType::Observed, finding: $finding);
    }

    /**
     * `$retryable` is carried separately from the exception itself — sanitized
     * metadata only, mirroring App\Domain\Payments\RecoveryErrorFormatter's
     * own "structured fields, never the raw message" rule — so a CLI/log
     * consumer can distinguish "will very likely resolve itself on the next
     * scheduled run" (retryable) from "needs a human to look at why this
     * provider call keeps failing" (not) without either implying a
     * financial finding exists.
     */
    public static function retrievalFailed(Throwable $exception, bool $retryable): self
    {
        return new self(ReconciliationOutcomeType::RetrievalFailed, exception: $exception, retryable: $retryable);
    }

    public static function skipped(string $reason): self
    {
        return new self(ReconciliationOutcomeType::Skipped, reason: $reason);
    }
}
