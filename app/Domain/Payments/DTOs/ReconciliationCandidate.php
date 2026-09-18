<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Payments\Enums\PaymentAttemptStatus;

/**
 * Everything App\Domain\Payments\Services\ReconciliationClassifier needs to
 * classify one PaymentAttempt, assembled once by
 * App\Domain\Payments\Services\ProviderReconciler from durable local reads
 * only — never from the provider response, which is passed separately as a
 * ProviderPaymentResult (or its absence). Kept a plain, side-effect-free
 * value object so classification itself stays a pure function — see
 * docs/financial/RECONCILIATION.md §7's architecture diagram.
 *
 * `expectedAmountMinorUnits`/`expectedCurrency`/`expectedCorrelationId` are
 * derived with the exact same formula
 * App\Domain\Payments\Services\PaymentService::assertResultMatchesAttempt()
 * already uses to validate a provider result at settlement time — the same
 * source of truth, not a second, potentially-drifting derivation.
 */
final readonly class ReconciliationCandidate
{
    public function __construct(
        public int $paymentAttemptId,
        public string $provider,
        public string $providerReference,
        public PaymentAttemptStatus $localAttemptStatus,
        public int $expectedAmountMinorUnits,
        public string $expectedCurrency,
        public string $expectedCorrelationId,
        public bool $hasPendingProviderEvent,
    ) {}
}
