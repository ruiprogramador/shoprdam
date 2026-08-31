<?php

use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventType;
use App\Domain\Payments\Exceptions\PaymentAttemptNotFoundException;
use App\Domain\Payments\MinorUnits;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletTransactionService;

/**
 * Proves PaymentEventProcessor::markSettled() resolves the PaymentAttempt it
 * transitions by the exact `(payment_id, provider, provider_reference)` the
 * settling Wallet transaction was recorded under — never by
 * Payment.current_payment_attempt_id, which only ever names whichever
 * attempt is *currently* gating new-attempt creation, not which attempt
 * historically claimed a given provider reference. See
 * PaymentEventProcessor::markSettled()'s docblock and
 * docs/wallet/integrations.md.
 *
 * Attempt states below are constructed directly (as
 * ReconcileOrphanedPaymentAttemptsTest and PaymentServiceGatingTest already
 * do) rather than driven through the full claim/webhook flow twice, so each
 * test can put current_payment_attempt_id in a state that's deliberately
 * stale relative to the event under test — the exact adversarial condition
 * this hardening closes.
 */
function claimedAttemptWithPendingTransaction(Payment $payment, Order $order, string $provider, string $reference): PaymentAttempt
{
    $attempt = PaymentAttempt::create([
        'payment_id' => $payment->id,
        'provider' => $provider,
        'method' => 'card',
        'provider_reference' => $reference,
        'idempotency_key' => "payment-{$payment->id}-attempt-{$provider}-{$reference}",
        'status' => PaymentAttemptStatus::Claimed,
    ]);

    app(WalletTransactionService::class)->record(
        wallet: $order->store->wallets()->first(),
        categorySlug: 'sale',
        amount: $order->amount,
        reference: new WalletTransactionReference($provider, $reference),
        options: ['status' => 'pending', 'referenceable' => $order],
    );

    return $attempt;
}

function exactSettlementSucceeded(string $provider, string $reference, string $eventId): ProviderEventOutcome
{
    return new ProviderEventOutcome(
        provider: $provider,
        eventId: $eventId,
        eventType: 'test.succeeded',
        type: ProviderEventType::Succeeded,
        providerReference: $reference,
    );
}

function exactSettlementFailed(string $provider, string $reference, string $eventId): ProviderEventOutcome
{
    return new ProviderEventOutcome(
        provider: $provider,
        eventId: $eventId,
        eventType: 'test.failed',
        type: ProviderEventType::Failed,
        providerReference: $reference,
        failureReason: 'declined',
    );
}

function exactSettlementRefunded(string $provider, string $reference, string $reversalReference, int $amountMinorUnits, string $eventId): ProviderEventOutcome
{
    return new ProviderEventOutcome(
        provider: $provider,
        eventId: $eventId,
        eventType: 'test.refunded',
        type: ProviderEventType::Refunded,
        providerReference: $reference,
        reversalReference: $reversalReference,
        refundedAmountMinorUnits: $amountMinorUnits,
    );
}

// --- Adversarial: a late event for a superseded attempt must never mutate the current one ---

it('a late failed event for an attempt that is no longer current only mutates that historical attempt, never the current one', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    $attemptA = claimedAttemptWithPendingTransaction($payment, $order, 'stripe', 'pi_old');
    $attemptB = claimedAttemptWithPendingTransaction($payment, $order, 'other_provider', 'pi_new');

    // B has since become current — A is purely historical.
    $payment->update(['current_payment_attempt_id' => $attemptB->id]);

    app(PaymentEventProcessor::class)->apply(exactSettlementFailed('stripe', 'pi_old', 'evt_late_fail'));

    expect($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_old')->firstOrFail()->status->slug)
        ->toBe('failed')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_new')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($payment->fresh()->current_payment_attempt_id)
        ->toBe($attemptB->id);
});

it('a late succeeded event for an attempt that is no longer current only mutates that historical attempt, never the current one', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    $attemptA = claimedAttemptWithPendingTransaction($payment, $order, 'stripe', 'pi_old');
    $attemptB = claimedAttemptWithPendingTransaction($payment, $order, 'other_provider', 'pi_new');

    $payment->update(['current_payment_attempt_id' => $attemptB->id]);

    app(PaymentEventProcessor::class)->apply(exactSettlementSucceeded('stripe', 'pi_old', 'evt_late_succeed'));

    expect($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and(StoreWalletTransaction::where('external_reference', 'pi_old')->firstOrFail()->status->slug)
        ->toBe('completed')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_new')->firstOrFail()->status->slug)
        ->toBe('pending')
        // Payment-level status does reflect the (surprising, but semantically
        // valid per the incoming event) aggregate outcome — only the
        // *attempt* targeting is under test here.
        ->and($payment->fresh()->status)
        ->toBe(PaymentStatus::Paid)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50');
});

// --- Normal path ---

it('a succeeded event for the current attempt settles that exact attempt', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    $attempt = claimedAttemptWithPendingTransaction($payment, $order, 'stripe', 'pi_current');
    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    app(PaymentEventProcessor::class)->apply(exactSettlementSucceeded('stripe', 'pi_current', 'evt_succeed'));

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('a terminal failed event for the current attempt settles that exact attempt without failing the Payment', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    $attempt = claimedAttemptWithPendingTransaction($payment, $order, 'stripe', 'pi_current');
    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    app(PaymentEventProcessor::class)->apply(exactSettlementFailed('stripe', 'pi_current', 'evt_fail'));

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

// --- Duplicate delivery stays a financial no-op and never touches another attempt ---

it('a duplicate succeeded event cannot re-settle or affect a different attempt', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    $attemptA = claimedAttemptWithPendingTransaction($payment, $order, 'stripe', 'pi_a');
    $attemptB = claimedAttemptWithPendingTransaction($payment, $order, 'other_provider', 'pi_b');
    $payment->update(['current_payment_attempt_id' => $attemptA->id]);

    app(PaymentEventProcessor::class)->apply(exactSettlementSucceeded('stripe', 'pi_a', 'evt_succeed_1'));

    $processor = app(PaymentEventProcessor::class);
    $processor->apply(exactSettlementSucceeded('stripe', 'pi_a', 'evt_succeed_2'));
    $processor->apply(exactSettlementSucceeded('stripe', 'pi_a', 'evt_succeed_2'));

    expect($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Claimed)
        ->and($wallet->fresh()->balance)
        ->toBe('42.50')
        ->and(StoreWalletTransaction::where('external_reference', 'pi_a')->count())
        ->toBe(1);
});

it('a duplicate failed event cannot re-settle or affect a different attempt', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    $attemptA = claimedAttemptWithPendingTransaction($payment, $order, 'stripe', 'pi_a');
    $attemptB = claimedAttemptWithPendingTransaction($payment, $order, 'other_provider', 'pi_b');
    $payment->update(['current_payment_attempt_id' => $attemptA->id]);

    $processor = app(PaymentEventProcessor::class);
    $processor->apply(exactSettlementFailed('stripe', 'pi_a', 'evt_fail_1'));
    $processor->apply(exactSettlementFailed('stripe', 'pi_a', 'evt_fail_2'));

    expect($attemptA->fresh()->status)
        ->toBe(PaymentAttemptStatus::Failed)
        ->and($attemptB->fresh()->status)
        ->toBe(PaymentAttemptStatus::Claimed);
});

// --- Refund path: attemptStatus stays null by design, no attempt transition invented ---

it('a refund never invents an attempt transition, even with a historical attempt on record', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    $attempt = claimedAttemptWithPendingTransaction($payment, $order, 'stripe', 'pi_refund');
    $payment->update(['current_payment_attempt_id' => $attempt->id]);

    $processor = app(PaymentEventProcessor::class);
    $processor->apply(exactSettlementSucceeded('stripe', 'pi_refund', 'evt_succeed'));

    expect($attempt->fresh()->status)->toBe(PaymentAttemptStatus::Succeeded);

    $amountMinorUnits = MinorUnits::fromDecimal($order->amount);
    $processor->apply(exactSettlementRefunded('stripe', 'pi_refund', 'ch_refund', $amountMinorUnits, 'evt_refund'));

    expect($attempt->fresh()->status)
        // Refund settlement never transitions the attempt — it stays at
        // whatever terminal state the original settlement left it in.
        ->toBe(PaymentAttemptStatus::Succeeded)
        ->and($payment->fresh()->status)
        ->toBe(PaymentStatus::Refunded)
        ->and($wallet->fresh()->balance)
        ->toBe('0.00');
});

// --- Fail closed: no attempt claims the exact reference being settled ---

it('fails closed and rolls back the Wallet mutation when no PaymentAttempt claims the exact settling reference', function () {
    $store = Store::factory()->create();
    $order = Order::factory()->forStore($store)->amount('42.50')->create();
    $payment = Payment::create(['order_id' => $order->id]);

    // A Wallet transaction exists for this reference, but — simulating data
    // corruption / a PaymentAttempt row that was never written — no
    // PaymentAttempt claims it.
    app(WalletTransactionService::class)->record(
        wallet: $store->wallets()->first(),
        categorySlug: 'sale',
        amount: $order->amount,
        reference: new WalletTransactionReference('stripe', 'pi_orphaned'),
        options: ['status' => 'pending', 'referenceable' => $order],
    );

    expect(fn () => app(PaymentEventProcessor::class)->apply(
        exactSettlementFailed('stripe', 'pi_orphaned', 'evt_orphaned_fail')
    ))->toThrow(PaymentAttemptNotFoundException::class);

    // The Wallet mutation inside the same DB::transaction() must have
    // rolled back too — the transaction is still pending, not failed.
    expect(StoreWalletTransaction::where('external_reference', 'pi_orphaned')->firstOrFail()->status->slug)
        ->toBe('pending')
        ->and($payment->fresh()->status)
        ->toBe(PaymentStatus::Pending);
});
