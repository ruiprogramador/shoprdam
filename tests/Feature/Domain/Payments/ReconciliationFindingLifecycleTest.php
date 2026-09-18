<?php

use App\Domain\Payments\DTOs\ReconciliationCandidate;
use App\Domain\Payments\DTOs\ReconciliationClassification;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationResolutionReason;
use App\Domain\Payments\Enums\ReconciliationSeverity;
use App\Domain\Payments\Enums\ReconciliationStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\ReconciliationFinding;
use App\Domain\Payments\Services\ReconciliationFindingRepository;
use App\Models\Admin;
use App\Models\Order;
use App\Models\Store;

/**
 * Every row of docs/financial/RECONCILIATION.md §10's transition table,
 * plus §9's episode-identity guarantees. Exercises
 * App\Domain\Payments\Services\ReconciliationFindingRepository directly —
 * the only class allowed to write to payment_reconciliation_findings.
 */

/**
 * payment_attempt_id is a real, restrictOnDelete() foreign key — a genuine
 * PaymentAttempt row is required, not an arbitrary id, since SQLite foreign
 * key enforcement is active in this test suite (verified by
 * tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest already
 * relying on it).
 */
function reconciliationFixtureAttemptId(): int
{
    $order = Order::factory()->forStore(Store::factory()->create())->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => 'stripe',
        // unique(provider, provider_reference) on payment_attempts — each
        // fixture needs its own reference, since these tests only care
        // about the FK target existing, never about attempt-level identity.
        'provider_reference' => "fixture_ref_{$payment->id}",
        'method' => 'card',
        'idempotency_key' => "payment-{$payment->id}-attempt-fixture",
        'status' => PaymentAttemptStatus::Claimed,
    ]);

    return $attempt->id;
}

function candidateFor(string $provider = 'stripe', string $reference = 'ref_1', ?int $attemptId = null): ReconciliationCandidate
{
    return new ReconciliationCandidate(
        paymentAttemptId: $attemptId ?? reconciliationFixtureAttemptId(),
        provider: $provider,
        providerReference: $reference,
        localAttemptStatus: PaymentAttemptStatus::Claimed,
        expectedAmountMinorUnits: 4250,
        expectedCurrency: 'eur',
        expectedCorrelationId: '1',
        hasPendingProviderEvent: false,
    );
}

function classificationOf(ReconciliationCategory $category, ReconciliationSeverity $severity = ReconciliationSeverity::High): ReconciliationClassification
{
    return new ReconciliationClassification(
        category: $category,
        severity: $severity,
        localState: 'claimed',
        remoteState: 'succeeded',
        localAmountMinorUnits: 4250,
        remoteAmountMinorUnits: 4250,
        localCurrency: 'eur',
        remoteCurrency: 'eur',
        localCorrelationId: '1',
        remoteCorrelationId: '1',
    );
}

it('opens a new episode on the first mismatch observed for a reference with no currently-open episode', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $finding = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(ReconciliationStatus::Open)
        ->and($finding->category)->toBe(ReconciliationCategory::RemoteMissing)
        ->and($finding->observation_count)->toBe(1)
        ->and($finding->active_identity)->toBe('stripe:ref_1')
        ->and($finding->first_observed_at->equalTo($finding->last_observed_at))->toBeTrue();
});

it('updates the same open episode, not a new row, when the same mismatch is observed again', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $first = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));

    $firstObservedAt = $first->first_observed_at;

    $second = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));

    expect($second->id)->toBe($first->id)
        ->and($second->observation_count)->toBe(2)
        ->and($second->first_observed_at->equalTo($firstObservedAt))->toBeTrue()
        ->and(ReconciliationFinding::count())->toBe(1);
});

it('updates the same open episode, not a new row, when the category changes while continuously unresolved', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $first = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));

    $second = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::AmountMismatch));

    expect($second->id)->toBe($first->id)
        ->and($second->category)->toBe(ReconciliationCategory::AmountMismatch)
        ->and($second->observation_count)->toBe(2)
        ->and(ReconciliationFinding::count())->toBe(1);
});

it('resolves the open episode, setting active_identity to null atomically, when a Match is observed', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $finding = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));

    $resolved = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::Match));

    expect($resolved->id)->toBe($finding->id)
        ->and($resolved->status)->toBe(ReconciliationStatus::Resolved)
        ->and($resolved->resolution_reason)->toBe(ReconciliationResolutionReason::NoLongerObserved)
        ->and($resolved->active_identity)->toBeNull()
        ->and($resolved->resolved_at)->not->toBeNull();
});

it('never creates a row at all for a Match with no currently-open episode', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $result = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::Match));

    expect($result)->toBeNull()
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('opens a brand-new episode, preserving the old one untouched, when a resolved reference mismatches again later', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $first = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));
    $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::Match));

    $second = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::AmountMismatch));

    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(ReconciliationStatus::Open)
        ->and($second->observation_count)->toBe(1)
        ->and(ReconciliationFinding::count())->toBe(2)
        // The old episode's own history is untouched.
        ->and($first->fresh()->status)->toBe(ReconciliationStatus::Resolved)
        ->and($first->fresh()->resolution_reason)->toBe(ReconciliationResolutionReason::NoLongerObserved);
});

it('never lets two open episodes exist simultaneously for the same (provider, provider_reference) identity', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));
    $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::AmountMismatch));
    $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::CurrencyMismatch));

    expect(ReconciliationFinding::where('status', ReconciliationStatus::Open)->count())->toBe(1)
        ->and(ReconciliationFinding::count())->toBe(1);
});

it('keeps episodes for different provider references completely independent', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $repository->recordObservation(candidateFor(reference: 'ref_1'), classificationOf(ReconciliationCategory::RemoteMissing));
    $repository->recordObservation(candidateFor(reference: 'ref_2'), classificationOf(ReconciliationCategory::AmountMismatch));

    expect(ReconciliationFinding::count())->toBe(2)
        ->and(ReconciliationFinding::where('status', ReconciliationStatus::Open)->count())->toBe(2);
});

it('does not create a unique-constraint collision across two different providers using the same raw reference string', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $repository->recordObservation(candidateFor(provider: 'stripe', reference: 'shared_ref'), classificationOf(ReconciliationCategory::RemoteMissing));
    $repository->recordObservation(candidateFor(provider: 'easypay', reference: 'shared_ref'), classificationOf(ReconciliationCategory::RemoteMissing));

    expect(ReconciliationFinding::count())->toBe(2);
});

it('acknowledgement never changes status or resolved_at, and independently coexists with an open episode', function () {
    $repository = app(ReconciliationFindingRepository::class);

    $finding = $repository->recordObservation(candidateFor(), classificationOf(ReconciliationCategory::RemoteMissing));

    $admin = Admin::factory()->create();

    $finding->update([
        'acknowledged_at' => now(),
        'acknowledged_by' => $admin->id,
    ]);

    expect($finding->fresh()->status)->toBe(ReconciliationStatus::Open)
        ->and($finding->fresh()->resolved_at)->toBeNull()
        ->and($finding->fresh()->acknowledged_at)->not->toBeNull()
        ->and($finding->fresh()->acknowledged_by)->toBe($admin->id);
});

it('two concurrent observations for the same identity converge on one row via unique(active_identity), never two', function () {
    // Sequential simulation of the real race, per this codebase's own
    // documented concurrency-testing convention (INVARIANTS.md's
    // "Concurrency note"): pre-insert the state a "first" worker would have
    // already committed, then call the real code path a "second" worker
    // would run against the current database state.
    $repository = app(ReconciliationFindingRepository::class);

    ReconciliationFinding::create([
        'payment_attempt_id' => reconciliationFixtureAttemptId(),
        'provider' => 'stripe',
        'provider_reference' => 'race_ref',
        'active_identity' => 'stripe:race_ref',
        'category' => ReconciliationCategory::RemoteMissing,
        'severity' => ReconciliationSeverity::High,
        'status' => ReconciliationStatus::Open,
        'first_observed_at' => now(),
        'last_observed_at' => now(),
        'observation_count' => 1,
    ]);

    $second = $repository->recordObservation(
        candidateFor(reference: 'race_ref'),
        classificationOf(ReconciliationCategory::AmountMismatch),
    );

    expect(ReconciliationFinding::count())->toBe(1)
        ->and($second->observation_count)->toBe(2)
        ->and($second->category)->toBe(ReconciliationCategory::AmountMismatch);
});
