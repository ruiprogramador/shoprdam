<?php

use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Enums\ReconciliationCategory;
use App\Domain\Payments\Enums\ReconciliationOutcomeType;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Models\ReconciliationFinding;
use App\Domain\Payments\Services\ProviderReconciler;
use App\Models\Order;
use App\Models\Store;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\PermissionException;
use Tests\Fakes\FakeEasyPayHttpClient;
use Tests\Fakes\FakeStripeHttpClient;

/**
 * App\Domain\Payments\Services\ProviderReconciler is the orchestrator for
 * Phase 1 (docs/financial/RECONCILIATION.md §7): local read -> provider GET
 * (outside any transaction) -> classify -> persist. This file additionally
 * proves the evidence-correctness hardening in §8/§13: RemoteMissing may
 * only ever be produced from a provider's own confirmed "resource doesn't
 * exist" signal, never from `FailureClass::NonRetryable` alone — a much
 * broader bucket that also covers auth failure, permission failure, a
 * malformed request, and other definitive rejections that prove nothing
 * about whether the resource exists.
 */
afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

function reconciliationClaimedAttempt(Order $order, string $providerReference = 'pi_reconcile_target', string $provider = 'stripe', string $method = 'card'): PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => $provider,
        'method' => $method,
        'provider_reference' => $providerReference,
        'idempotency_key' => "payment-{$payment->id}-attempt-reconcile",
        'status' => PaymentAttemptStatus::Claimed,
    ]);

    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    return $attempt->fresh();
}

// ---------------------------------------------------------------------
// Baseline behavior (unchanged by the hardening)
// ---------------------------------------------------------------------

it('records a Match observation (which never creates a row) when the provider agrees with local state', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_reconcile_target' => [
            'id' => 'pi_reconcile_target',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::Observed)
        ->and($outcome->finding)->toBeNull()
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('opens a RemoteSucceededNoSettlementPath finding when the provider reports succeeded with no stored event', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_reconcile_target' => [
            'id' => 'pi_reconcile_target',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::Observed)
        ->and($outcome->finding->category)->toBe(ReconciliationCategory::RemoteSucceededNoSettlementPath)
        ->and($outcome->finding->provider)->toBe('stripe')
        ->and($outcome->finding->provider_reference)->toBe('pi_reconcile_target')
        // Never touched — this is the whole point of the boundary.
        ->and($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Claimed);
});

it('opens a RemoteSucceededAwaitingReplay finding, at Low severity, when a pending provider event already exists', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_awaiting_replay',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_reconcile_target',
        'payload' => [],
        'status' => ProviderEventStatus::Pending,
    ]);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_reconcile_target' => [
            'id' => 'pi_reconcile_target',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->finding->category)->toBe(ReconciliationCategory::RemoteSucceededAwaitingReplay)
        ->and($outcome->finding->severity->value)->toBe('low');
});

it('never mutates Payment, PaymentAttempt, Order, or Wallet state while reconciling', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_reconcile_target' => [
            'id' => 'pi_reconcile_target',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'succeeded',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    app(ProviderReconciler::class)->reconcile($attempt);

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Claimed)
        ->and($attempt->fresh()->payment->status->value)->toBe('pending')
        ->and($order->fresh()->status->slug)->toBe('pending')
        ->and($wallet->fresh()->balance)->toBe('0.00')
        ->and($wallet->transactions()->count())->toBe(0)
        ->and(PaymentProviderEvent::count())->toBe(0);
});

// ---------------------------------------------------------------------
// A — Stripe: confirmed exact-resource missing -> RemoteMissing
// ---------------------------------------------------------------------

it('[A] Stripe: a clean 404 ("no such payment_intent") is confirmed absence -> RemoteMissing', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'invalid_request_error', 'message' => 'No such payment_intent']],
        responseCode: 404,
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::Observed)
        ->and($outcome->finding->category)->toBe(ReconciliationCategory::RemoteMissing);
});

// ---------------------------------------------------------------------
// B/C — timeout / connection error -> no finding, RetrievalFailed
// ---------------------------------------------------------------------

it('[B] Stripe: a timeout-shaped connection failure produces no finding', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        throws: new ApiConnectionException('Simulated network failure reaching Stripe'),
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->retryable)->toBeTrue()
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('[C] EasyPay: a connection error produces no finding', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order, providerReference: 'ep_conn_fail', provider: 'easypay', method: 'mbway');

    FakeEasyPayHttpClient::connectionFailure()->install();

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->retryable)->toBeTrue()
        ->and(ReconciliationFinding::count())->toBe(0);
});

// ---------------------------------------------------------------------
// D — provider 5xx -> no finding (retryable)
// ---------------------------------------------------------------------

it('[D] Stripe: a 500 response produces no finding', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'api_error', 'message' => 'Simulated Stripe outage']],
        responseCode: 500,
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->retryable)->toBeTrue()
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('[D] EasyPay: a 500 response produces no finding', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order, providerReference: 'ep_500', provider: 'easypay', method: 'mbway');

    (new FakeEasyPayHttpClient(responseBody: ['message' => 'Simulated EasyPay outage'], responseCode: 500))->install();

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->retryable)->toBeTrue()
        ->and(ReconciliationFinding::count())->toBe(0);
});

// ---------------------------------------------------------------------
// E/F — auth / permission failure -> no RemoteMissing
// ---------------------------------------------------------------------

it('[E] Stripe: an authentication failure never produces RemoteMissing', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']],
        responseCode: 401,
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->retryable)->toBeFalse()
        ->and($outcome->exception)->toBeInstanceOf(AuthenticationException::class)
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('[F] Stripe: a permission failure never produces RemoteMissing', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'invalid_request_error', 'message' => 'Permission denied for this resource'], 'code' => 'permission_error'],
        responseCode: 403,
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->exception)->toBeInstanceOf(PermissionException::class)
        ->and(ReconciliationFinding::count())->toBe(0);
});

// ---------------------------------------------------------------------
// G — generic non-retryable 4xx that is NOT 404 -> no RemoteMissing
// ---------------------------------------------------------------------

it('[G] Stripe: a non-retryable 400 (malformed request, not "not found") never produces RemoteMissing', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid id format']],
        responseCode: 400,
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('[G] EasyPay: a 404-shaped response STILL never produces RemoteMissing — the provider cannot confirm absence', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order, providerReference: 'ep_404', provider: 'easypay', method: 'mbway');

    (new FakeEasyPayHttpClient(responseBody: ['message' => 'Payment not found'], responseCode: 404))->install();

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->retryable)->toBeFalse()
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('[G] EasyPay: a 422 response never produces RemoteMissing', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order, providerReference: 'ep_422', provider: 'easypay', method: 'mbway');

    (new FakeEasyPayHttpClient(responseBody: ['message' => 'Unprocessable'], responseCode: 422))->install();

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and(ReconciliationFinding::count())->toBe(0);
});

// ---------------------------------------------------------------------
// H — ambiguous / unrecognized SDK failure -> no RemoteMissing
// ---------------------------------------------------------------------

it('[H] Stripe: a CardException (non-retryable, meaningless for a GET) never produces RemoteMissing', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'card_error', 'message' => 'Your card was declined.']],
        responseCode: 402,
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and($outcome->exception)->toBeInstanceOf(CardException::class)
        ->and(ReconciliationFinding::count())->toBe(0);
});

it('[H] an unrecognized exception type (not a known Stripe class at all) never produces RemoteMissing', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    // classifyFailure()'s own default arm treats a wholly unrecognized
    // exception as Retryable — still correctly never RemoteMissing, via the
    // "no evidence at all" branch rather than the confirmed-absence one.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        throws: new RuntimeException('Simulated unexpected SDK failure'),
    ));

    $outcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($outcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and(ReconciliationFinding::count())->toBe(0);
});

// ---------------------------------------------------------------------
// I — existing open finding + subsequent retrieval failure -> untouched
// ---------------------------------------------------------------------

it('[I] a retrieval failure never touches an already-open finding for the same identity', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'invalid_request_error', 'message' => 'No such payment_intent']],
        responseCode: 404,
    ));

    $firstOutcome = app(ProviderReconciler::class)->reconcile($attempt);
    $finding = $firstOutcome->finding;

    expect($finding->category)->toBe(ReconciliationCategory::RemoteMissing)
        ->and($finding->observation_count)->toBe(1);

    $observedAt = $finding->last_observed_at;

    // A later run hits an authentication failure — no authoritative
    // evidence at all. The existing RemoteMissing finding must not be
    // resolved, re-categorized, or have its observation count bumped as
    // though the mismatch had been re-observed.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(
        responseBody: ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']],
        responseCode: 401,
    ));

    $secondOutcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($secondOutcome->type)->toBe(ReconciliationOutcomeType::RetrievalFailed)
        ->and(ReconciliationFinding::count())->toBe(1)
        ->and($finding->fresh()->category)->toBe(ReconciliationCategory::RemoteMissing)
        ->and($finding->fresh()->observation_count)->toBe(1)
        ->and($finding->fresh()->status->value)->toBe('open')
        ->and($finding->fresh()->last_observed_at->equalTo($observedAt))->toBeTrue();
});

// ---------------------------------------------------------------------
// J — existing open finding + later authoritative Match -> resolves normally
// ---------------------------------------------------------------------

it('[J] an existing open finding resolves normally once a later, authoritative retrieval agrees with local state', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $attempt = reconciliationClaimedAttempt($order);

    // First pass: amount mismatch opens a finding.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_reconcile_target' => [
            'id' => 'pi_reconcile_target',
            'object' => 'payment_intent',
            'amount' => 999,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    $firstOutcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($firstOutcome->finding->category)->toBe(ReconciliationCategory::AmountMismatch);

    // Second pass: the provider now reports the correct, matching amount —
    // genuine authoritative agreement, resolving the finding.
    ApiRequestor::setHttpClient(new FakeStripeHttpClient(responsesById: [
        'pi_reconcile_target' => [
            'id' => 'pi_reconcile_target',
            'object' => 'payment_intent',
            'amount' => 4250,
            'currency' => 'eur',
            'status' => 'requires_payment_method',
            'metadata' => ['order_id' => (string) $order->id],
        ],
    ]));

    $secondOutcome = app(ProviderReconciler::class)->reconcile($attempt);

    expect($secondOutcome->type)->toBe(ReconciliationOutcomeType::Observed)
        ->and(ReconciliationFinding::count())->toBe(1)
        ->and(ReconciliationFinding::first()->status->value)->toBe('resolved')
        ->and(ReconciliationFinding::first()->resolution_reason->value)->toBe('no_longer_observed');
});
