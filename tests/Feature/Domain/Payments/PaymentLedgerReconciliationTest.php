<?php

use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\ProviderEventType;
use App\Domain\Payments\MinorUnits;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Domain\Wallet\WalletTransactionReference;
use App\Models\Order;
use App\Models\Store;
use App\Services\Wallet\WalletLedgerAuditor;
use App\Services\Wallet\WalletTransactionService;

/**
 * Real-flow LEDGER invariant tests for the Payment/refund path — not unit
 * calls into WalletTransactionService directly, but the actual
 * PaymentEventProcessor entry point a live webhook would use, then
 * WalletLedgerAuditor::auditWallet() checking the result against the
 * ledger. Distinct helper names from
 * PaymentEventProcessorExactAttemptSettlementTest's (same shapes) — Pest
 * merges every Feature test file's top-level functions into one shared
 * namespace for a full-suite run.
 */
function reconOrder(string $amount = '100.00'): Order
{
    $store = Store::factory()->create();

    return Order::factory()->forStore($store)->amount($amount)->create();
}

function reconClaimedSale(Order $order, string $provider, string $reference): PaymentAttempt
{
    $payment = Payment::create(['order_id' => $order->id]);

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

function reconSucceeded(string $provider, string $reference, string $eventId): ProviderEventOutcome
{
    return new ProviderEventOutcome(
        provider: $provider,
        eventId: $eventId,
        eventType: 'test.succeeded',
        type: ProviderEventType::Succeeded,
        providerReference: $reference,
    );
}

function reconRefunded(string $provider, string $reference, string $reversalReference, int $amountMinorUnits, string $eventId): ProviderEventOutcome
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

function reconAuditor(): WalletLedgerAuditor
{
    return app(WalletLedgerAuditor::class);
}

it('LEDGER: a settled sale reconciles exactly — expected balance equals the order amount, zero drift', function () {
    $order = reconOrder('100.00');
    reconClaimedSale($order, 'stripe', 'pi_recon_1');

    app(PaymentEventProcessor::class)->apply(reconSucceeded('stripe', 'pi_recon_1', 'evt_recon_1'));

    $wallet = $order->store->wallets()->first()->fresh();
    $result = reconAuditor()->auditWallet($wallet);

    expect($wallet->balance)->toBe('100.00')
        ->and($result->expectedBalance)->toBe('100.00')
        ->and($result->isConsistent())->toBeTrue();
});

it('LEDGER-04: a full refund reconciles exactly — the reversal cannot exceed the original captured amount', function () {
    $order = reconOrder('100.00');
    reconClaimedSale($order, 'stripe', 'pi_recon_2');
    app(PaymentEventProcessor::class)->apply(reconSucceeded('stripe', 'pi_recon_2', 'evt_recon_2'));

    app(PaymentEventProcessor::class)->apply(
        reconRefunded('stripe', 'pi_recon_2', 're_recon_2', MinorUnits::fromDecimal('100.00'), 'evt_refund_2')
    );

    $wallet = $order->store->wallets()->first()->fresh();
    $result = reconAuditor()->auditWallet($wallet);

    expect($wallet->balance)->toBe('0.00')
        ->and($result->expectedBalance)->toBe('0.00')
        ->and($result->isConsistent())->toBeTrue();
});

it('LEDGER-04: a partial refund is never applied to the ledger — the sale amount stands, no drift is introduced', function () {
    $order = reconOrder('100.00');
    reconClaimedSale($order, 'stripe', 'pi_recon_3');
    app(PaymentEventProcessor::class)->apply(reconSucceeded('stripe', 'pi_recon_3', 'evt_recon_3'));

    // A partial refund (half the captured amount) — documented existing
    // behavior: silently ignored, never posted to the ledger. This test
    // proves that ignoring it never leaves the ledger inconsistent with
    // itself — the auditor still finds zero drift, because nothing was
    // ever half-applied.
    app(PaymentEventProcessor::class)->apply(
        reconRefunded('stripe', 'pi_recon_3', 're_recon_3', MinorUnits::fromDecimal('50.00'), 'evt_partial_refund_3')
    );

    $wallet = $order->store->wallets()->first()->fresh();
    $result = reconAuditor()->auditWallet($wallet);

    expect($wallet->balance)->toBe('100.00')
        ->and($result->expectedBalance)->toBe('100.00')
        ->and($result->isConsistent())->toBeTrue();
});

it('LEDGER-01: settling the same succeeded event twice produces exactly one ledger effect', function () {
    $order = reconOrder('75.00');
    reconClaimedSale($order, 'stripe', 'pi_recon_4');

    app(PaymentEventProcessor::class)->apply(reconSucceeded('stripe', 'pi_recon_4', 'evt_recon_4'));
    app(PaymentEventProcessor::class)->apply(reconSucceeded('stripe', 'pi_recon_4', 'evt_recon_4_dup'));

    $wallet = $order->store->wallets()->first()->fresh();
    $result = reconAuditor()->auditWallet($wallet);

    expect($wallet->balance)->toBe('75.00')
        ->and($result->expectedBalance)->toBe('75.00')
        ->and($result->completedTransactionCount)->toBe(1)
        ->and($result->isConsistent())->toBeTrue();
});
