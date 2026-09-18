<?php

use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\DTOs\ReconciliationCandidate;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationSeverity;
use App\Domain\Payments\Services\ReconciliationClassifier;

/**
 * Pure, side-effect-free classification tests — one per row of
 * docs/financial/RECONCILIATION.md §8's taxonomy, implementing §17's
 * requirement. No database, no Laravel container: ReconciliationClassifier
 * takes only plain DTOs/enums in and returns a plain DTO out.
 */
function candidate(
    string $provider = 'stripe',
    PaymentAttemptStatus $localStatus = PaymentAttemptStatus::Claimed,
    int $expectedAmount = 4250,
    string $expectedCurrency = 'eur',
    string $expectedCorrelationId = '1',
    bool $hasPendingProviderEvent = false,
): ReconciliationCandidate {
    return new ReconciliationCandidate(
        paymentAttemptId: 1,
        provider: $provider,
        providerReference: 'ref_1',
        localAttemptStatus: $localStatus,
        expectedAmountMinorUnits: $expectedAmount,
        expectedCurrency: $expectedCurrency,
        expectedCorrelationId: $expectedCorrelationId,
        hasPendingProviderEvent: $hasPendingProviderEvent,
    );
}

function result(
    string $status,
    int $amount = 4250,
    string $currency = 'eur',
    ?string $correlationId = '1',
): ProviderPaymentResult {
    return new ProviderPaymentResult(
        providerReference: 'ref_1',
        amountMinorUnits: $amount,
        currency: $currency,
        providerStatus: $status,
        correlationId: $correlationId,
    );
}

it('classifies a null retrieval result as RemoteMissing', function () {
    $classification = (new ReconciliationClassifier)->classify(candidate(), null);

    expect($classification->category)->toBe(ReconciliationCategory::RemoteMissing)
        ->and($classification->severity)->toBe(ReconciliationSeverity::High)
        ->and($classification->remoteState)->toBeNull();
});

it('classifies an unrecognized Stripe status as UnsupportedRemoteState', function () {
    $classification = (new ReconciliationClassifier)->classify(candidate(), result('some_future_stripe_status'));

    expect($classification->category)->toBe(ReconciliationCategory::UnsupportedRemoteState);
});

it('classifies EasyPay refunded as UnsupportedRemoteState, never as a settlement signal', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(provider: 'easypay'),
        result('refunded'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::UnsupportedRemoteState);
});

it('classifies an amount mismatch before ever considering status agreement', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(expectedAmount: 4250),
        result('succeeded', amount: 999),
    );

    expect($classification->category)->toBe(ReconciliationCategory::AmountMismatch)
        ->and($classification->severity)->toBe(ReconciliationSeverity::High);
});

it('classifies a currency mismatch', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(expectedCurrency: 'eur'),
        result('succeeded', currency: 'usd'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::CurrencyMismatch);
});

it('classifies a correlation mismatch', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(expectedCorrelationId: '1'),
        result('succeeded', correlationId: '2'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::CorrelationMismatch);
});

it('classifies remote succeeded + local Succeeded as a Match', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Succeeded),
        result('succeeded'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::Match);
});

it('classifies remote succeeded + local Failed as Ambiguous at High severity, never auto-corrected', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Failed),
        result('succeeded'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::Ambiguous)
        ->and($classification->severity)->toBe(ReconciliationSeverity::High);
});

it('classifies remote succeeded + local Claimed + a pending provider event as RemoteSucceededAwaitingReplay (Low)', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Claimed, hasPendingProviderEvent: true),
        result('succeeded'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::RemoteSucceededAwaitingReplay)
        ->and($classification->severity)->toBe(ReconciliationSeverity::Low);
});

it('classifies remote succeeded + local Claimed + no pending provider event as RemoteSucceededNoSettlementPath (High)', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Claimed, hasPendingProviderEvent: false),
        result('succeeded'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::RemoteSucceededNoSettlementPath)
        ->and($classification->severity)->toBe(ReconciliationSeverity::High);
});

it('classifies Stripe canceled + local Failed as a Match', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Failed),
        result('canceled'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::Match);
});

it('classifies Stripe canceled + local Succeeded as Ambiguous at High severity', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Succeeded),
        result('canceled'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::Ambiguous)
        ->and($classification->severity)->toBe(ReconciliationSeverity::High);
});

it('classifies Stripe canceled + local Claimed as RemoteFailedLocalPending', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Claimed),
        result('canceled'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::RemoteFailedLocalPending);
});

it('classifies a Stripe non-terminal status + local Claimed as a Match (both agree: still in flight)', function (string $status) {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: PaymentAttemptStatus::Claimed),
        result($status),
    );

    expect($classification->category)->toBe(ReconciliationCategory::Match);
})->with([
    'requires_payment_method',
    'requires_confirmation',
    'requires_action',
    'processing',
    'requires_capture',
]);

it('classifies a Stripe non-terminal status + local terminal as RemotePendingLocalTerminal', function (PaymentAttemptStatus $localStatus) {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(localStatus: $localStatus),
        result('requires_payment_method'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::RemotePendingLocalTerminal);
})->with([
    PaymentAttemptStatus::Succeeded,
    PaymentAttemptStatus::Failed,
]);

it('classifies EasyPay success + local Claimed + no pending event as RemoteSucceededNoSettlementPath', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(provider: 'easypay', localStatus: PaymentAttemptStatus::Claimed),
        result('success'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::RemoteSucceededNoSettlementPath);
});

it('classifies EasyPay failed + local Claimed as RemoteFailedLocalPending', function () {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(provider: 'easypay', localStatus: PaymentAttemptStatus::Claimed),
        result('failed'),
    );

    expect($classification->category)->toBe(ReconciliationCategory::RemoteFailedLocalPending);
});

it('classifies EasyPay non-terminal statuses + local Claimed as a Match', function (string $status) {
    $classification = (new ReconciliationClassifier)->classify(
        candidate(provider: 'easypay', localStatus: PaymentAttemptStatus::Claimed),
        result($status),
    );

    expect($classification->category)->toBe(ReconciliationCategory::Match);
})->with(['pending', 'waiting', 'delayed']);

it('never returns an actionable-shaped category — Phase 1 has no automatic action concept at all', function () {
    // There is no `actionable` field on ReconciliationClassification at all
    // (docs/financial/RECONCILIATION.md §10/§18) — this test documents that
    // fact by asserting the DTO's declared properties, so a future addition
    // of one is a deliberate, reviewed change, not an accidental one.
    $classification = (new ReconciliationClassifier)->classify(candidate(), result('succeeded'));

    expect(get_object_vars($classification))->not->toHaveKey('actionable');
});
