<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationSeverity;

/**
 * The pure, side-effect-free output of
 * App\Domain\Payments\Services\ReconciliationClassifier::classify() — never
 * itself a database row. App\Domain\Payments\Services\ReconciliationFindingRepository
 * turns this into a ReconciliationFinding write; nothing about producing
 * this DTO touches a database, a Wallet, or any provider.
 */
final readonly class ReconciliationClassification
{
    public function __construct(
        public ReconciliationCategory $category,
        public ReconciliationSeverity $severity,
        public string $localState,
        public ?string $remoteState,
        public ?int $localAmountMinorUnits,
        public ?int $remoteAmountMinorUnits,
        public ?string $localCurrency,
        public ?string $remoteCurrency,
        public ?string $localCorrelationId,
        public ?string $remoteCorrelationId,
    ) {}
}
