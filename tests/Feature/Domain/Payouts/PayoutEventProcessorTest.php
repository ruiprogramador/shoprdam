<?php

use App\Domain\Payouts\DTOs\PayoutProviderOutcome;
use App\Domain\Payouts\Enums\PayoutAttemptStatus;
use App\Domain\Payouts\Enums\PayoutOutcomeType;
use App\Domain\Payouts\Enums\PayoutStatus;
use App\Domain\Payouts\Exceptions\ExternalTransferReferenceAlreadyUsedException;
use App\Domain\Payouts\Exceptions\PayoutAttemptMismatchException;
use App\Domain\Payouts\Exceptions\PayoutAttemptNotFoundException;
use App\Domain\Payouts\Models\Payout;
use App\Domain\Payouts\Models\PayoutAttempt;
use App\Domain\Payouts\PayoutProviderManager;
use App\Domain\Payouts\Services\PayoutEventProcessor;
use App\Domain\Payouts\Services\PayoutService;
use App\Models\Store;
use App\Payouts\Testing\FakePayoutProvider;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;

function processor(): PayoutEventProcessor
{
    return app(PayoutEventProcessor::class);
}

function claimedPayoutAttempt(string $balance = '100.00', string $amount = '80.00'): PayoutAttempt
{
    app(PayoutProviderManager::class)->extend('fake', fn () => new FakePayoutProvider);

    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', $balance);

    $service = app(PayoutService::class);
    $payout = $service->request($store, 'EUR', $amount, 'key-'.$store->id);
    $attempt = $service->createDurableAttempt($payout, 'fake');

    return $service->finalizeAttempt($attempt);
}

function succeededOutcomeFor(PayoutAttempt $attempt, ?string $externalReference = null): PayoutProviderOutcome
{
    return new PayoutProviderOutcome(
        provider: $attempt->provider,
        providerReference: $attempt->provider_reference,
        type: PayoutOutcomeType::Succeeded,
        amount: $attempt->payout->amount,
        currency: $attempt->payout->currency->code,
        correlationId: (string) $attempt->payout_id,
        externalTransferReference: $externalReference ?? 'sepa-'.$attempt->id,
    );
}

function failedOutcomeFor(PayoutAttempt $attempt): PayoutProviderOutcome
{
    return new PayoutProviderOutcome(
        provider: $attempt->provider,
        providerReference: $attempt->provider_reference,
        type: PayoutOutcomeType::Failed,
        amount: $attempt->payout->amount,
        currency: $attempt->payout->currency->code,
        correlationId: (string) $attempt->payout_id,
        failureReason: 'rejected by bank',
    );
}

it('settles a succeeded outcome without posting a second wallet transaction', function () {
    $attempt = claimedPayoutAttempt();
    $balanceBefore = $attempt->payout->wallet->fresh()->balance;

    processor()->apply(succeededOutcomeFor($attempt, 'sepa-real-ref-1'));

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Succeeded)
        ->and($attempt->fresh()->external_transfer_reference)->toBe('sepa-real-ref-1')
        ->and($attempt->payout->fresh()->status)->toBe(PayoutStatus::Succeeded)
        ->and($attempt->payout->fresh()->completed_at)->not->toBeNull()
        ->and($attempt->payout->wallet->fresh()->balance)->toBe($balanceBefore);
});

it('treats a duplicate succeeded delivery as a safe no-op', function () {
    $attempt = claimedPayoutAttempt();

    processor()->apply(succeededOutcomeFor($attempt, 'sepa-real-ref-1'));
    processor()->apply(succeededOutcomeFor($attempt, 'sepa-real-ref-1'));

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Succeeded)
        ->and($attempt->fresh()->external_transfer_reference)->toBe('sepa-real-ref-1');
});

it('fails the attempt without reversing the reservation or resolving the payout', function () {
    $attempt = claimedPayoutAttempt();

    processor()->apply(failedOutcomeFor($attempt));

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Failed)
        ->and($attempt->payout->fresh()->status)->toBe(PayoutStatus::Reserved)
        ->and($attempt->payout->fresh()->current_payout_attempt_id)->toBe($attempt->id)
        ->and($attempt->payout->wallet->fresh()->balance)->toBe('20.00'); // still reserved, never reversed
});

it('EXACT CURRENT ATTEMPT ISOLATION: a duplicate/late failed outcome for a superseded attempt never touches the payout or the current attempt', function () {
    $attempt1 = claimedPayoutAttempt();
    $payout = $attempt1->payout;
    $service = app(PayoutService::class);

    // Attempt 1 fails for real — payout returns to Reserved, attempt 1 is now historical.
    processor()->apply(failedOutcomeFor($attempt1));
    expect($payout->fresh()->status)->toBe(PayoutStatus::Reserved);

    // Attempt 2 is created and claimed — it is now the current attempt.
    $attempt2 = $service->finalizeAttempt($service->createDurableAttempt($payout->fresh(), 'fake'));
    expect($payout->fresh()->current_payout_attempt_id)->toBe($attempt2->id)
        ->and($payout->fresh()->status)->toBe(PayoutStatus::Processing);

    // A duplicate/late delivery of attempt 1's own (already-applied) failed
    // outcome arrives now, after attempt 2 became current.
    processor()->apply(failedOutcomeFor($attempt1->fresh()));

    // Nothing about the current state may have moved because of it.
    expect($payout->fresh()->status)->toBe(PayoutStatus::Processing)
        ->and($payout->fresh()->current_payout_attempt_id)->toBe($attempt2->id)
        ->and($attempt2->fresh()->status)->toBe(PayoutAttemptStatus::Claimed);
});

it('throws when no attempt claims the given provider reference', function () {
    expect(fn () => processor()->apply(new PayoutProviderOutcome(
        provider: 'fake',
        providerReference: 'never-claimed',
        type: PayoutOutcomeType::Succeeded,
        amount: '10.00',
        currency: 'EUR',
        correlationId: '1',
        externalTransferReference: 'sepa-x',
    )))->toThrow(PayoutAttemptNotFoundException::class);
});

it('fails closed when a succeeded outcome does not match the payout amount', function () {
    $attempt = claimedPayoutAttempt();

    $mismatched = new PayoutProviderOutcome(
        provider: $attempt->provider,
        providerReference: $attempt->provider_reference,
        type: PayoutOutcomeType::Succeeded,
        amount: '999.00',
        currency: $attempt->payout->currency->code,
        correlationId: (string) $attempt->payout_id,
        externalTransferReference: 'sepa-x',
    );

    expect(fn () => processor()->apply($mismatched))
        ->toThrow(PayoutAttemptMismatchException::class);

    expect($attempt->fresh()->status)->toBe(PayoutAttemptStatus::Claimed);
});

it('rejects a real bank reference already used as evidence for a different attempt', function () {
    $attemptA = claimedPayoutAttempt();
    $attemptB = claimedPayoutAttempt();

    processor()->apply(succeededOutcomeFor($attemptA, 'sepa-shared-ref'));

    expect(fn () => processor()->apply(succeededOutcomeFor($attemptB, 'sepa-shared-ref')))
        ->toThrow(ExternalTransferReferenceAlreadyUsedException::class);

    expect($attemptB->fresh()->status)->toBe(PayoutAttemptStatus::Claimed)
        ->and($attemptB->fresh()->external_transfer_reference)->toBeNull();
});

it('never lets two admins confirming the same attempt at once produce two settlements', function () {
    // Simulates the interleaving directly (see PaymentAttemptIdempotencyKeyUniquenessTest
    // for the same "single-process test harness" rationale): the CAS's own
    // WHERE clause is what actually decides the winner in a real race, not
    // this test's call ordering.
    $attempt = claimedPayoutAttempt();

    processor()->apply(succeededOutcomeFor($attempt, 'sepa-admin-a'));
    processor()->apply(succeededOutcomeFor($attempt->fresh(), 'sepa-admin-b'));

    // First writer wins; external_transfer_reference is immutable afterwards.
    expect($attempt->fresh()->external_transfer_reference)->toBe('sepa-admin-a');
});
