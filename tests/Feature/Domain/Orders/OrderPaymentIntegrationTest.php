<?php

use App\Domain\Orders\Events\OrderTransitioned;
use App\Domain\Orders\Exceptions\InvalidOrderTransitionException;
use App\Domain\Payments\DTOs\ProviderEventOutcome;
use App\Domain\Payments\Enums\EventApplicationOutcome;
use App\Domain\Payments\Enums\PaymentAttemptStatus;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\ProviderEventStatus;
use App\Domain\Payments\Enums\ProviderEventType;
use App\Domain\Payments\Exceptions\PaymentAttemptNotFoundException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentAttempt;
use App\Domain\Payments\Models\PaymentProviderEvent;
use App\Domain\Payments\Services\PaymentEventProcessor;
use App\Domain\Wallet\WalletTransactionReference;
use App\Enums\TransactionSource;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The Payment -> Order relationship (docs/financial/ORDER-LIFECYCLE.md §5):
 * PaymentEventProcessor is the only production caller of the Order
 * lifecycle, and only ever AFTER it has settled the Wallet — canonical
 * financial settlement first, allowed Order transition second, never the
 * reverse. Runs the real processor end to end; nothing here fakes settlement.
 */
function opOrder(): Order
{
    return Order::factory()->forStore(Store::factory()->create())->amount('42.50')->create();
}

/**
 * A claimed attempt with its pending sale — what PaymentService::claimProviderReference() commits.
 * `$withAttempt = false` leaves the sale WITHOUT a claiming PaymentAttempt, which makes
 * markSettled() fail at its very last step (attempt lookup) — after the Order transition.
 */
function opAttempt(Order $order, string $reference, bool $withAttempt = true): ?PaymentAttempt
{
    $payment = Payment::firstOrCreate(['order_id' => $order->id]);

    $attempt = null;

    if ($withAttempt) {
        $attempt = PaymentAttempt::create([
            'payment_id' => $payment->id,
            'provider' => 'stripe',
            'method' => 'card',
            'provider_reference' => $reference,
            'idempotency_key' => "payment-{$payment->id}-attempt-{$reference}",
            'status' => PaymentAttemptStatus::Claimed,
        ]);
        $payment->update(['current_payment_attempt_id' => $attempt->id]);
    }

    app(WalletTransactionService::class)->record(
        wallet: app(WalletService::class)->getOrCreateWallet($order->store, $order->currency->code),
        categorySlug: 'sale',
        amount: $order->amount,
        reference: new WalletTransactionReference('stripe', $reference),
        options: ['status' => 'pending', 'referenceable' => $order, 'source' => TransactionSource::Api],
    );

    return $attempt;
}

function opEvent(ProviderEventType $type, string $reference, string $eventId, array $extra = []): ProviderEventOutcome
{
    return new ProviderEventOutcome(...[
        'provider' => 'stripe',
        'eventId' => $eventId,
        'eventType' => 'test.'.$type->name,
        'type' => $type,
        'providerReference' => $reference,
        ...$extra,
    ]);
}

function opOrderState(Order $order): string
{
    return $order->fresh()->status->slug;
}

/** The whole settlement footprint of one reference, for atomicity assertions. */
function opFootprint(Order $order, string $reference): array
{
    $sale = StoreWalletTransaction::where('external_reference', $reference)->firstOrFail();

    return [
        'order' => opOrderState($order),
        'paid_at' => $order->fresh()->paid_at?->toIso8601String(),
        'sale' => $sale->status->slug,
        'wallet' => $sale->storeWallet->fresh()->balance,
        'payment' => Payment::where('order_id', $order->id)->first()->status->value,
        'attempt' => PaymentAttempt::where('provider_reference', $reference)->first()?->status->value,
    ];
}

/** Process-global static state — always cleared, or it would leak into every later test. */
function opWithMorphMap(array $map, Closure $fn): mixed
{
    Relation::morphMap($map);

    try {
        return $fn();
    } finally {
        (new ReflectionProperty(Relation::class, 'morphMap'))->setValue(null, []);
    }
}

it('a successful payment moves the Order to paid through the lifecycle boundary, recording paid_at and one event', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    opAttempt($order, 'pi_op_ok');

    app(PaymentEventProcessor::class)->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_ok', 'evt_ok'));

    expect(opOrderState($order))->toBe('paid')
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and(Payment::where('order_id', $order->id)->first()->status)->toBe(PaymentStatus::Paid);

    Event::assertDispatchedTimes(OrderTransitioned::class, 1);
    Event::assertDispatched(OrderTransitioned::class, fn ($e) => $e->from === 'pending' && $e->to === 'paid');
});

it('a PaymentAttempt failure leaves the Payment pending and the Order payable — a later attempt still pays it (ORDER-06)', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    $processor = app(PaymentEventProcessor::class);

    $attemptA = opAttempt($order, 'pi_op_a');
    $processor->apply(opEvent(ProviderEventType::Failed, 'pi_op_a', 'evt_a_fail', ['failureReason' => 'declined']));

    // The attempt failed; neither the Payment aggregate nor the Order is terminal.
    expect($attemptA->fresh()->status)->toBe(PaymentAttemptStatus::Failed)
        ->and(Payment::where('order_id', $order->id)->first()->status)->toBe(PaymentStatus::Pending)
        ->and(opOrderState($order))->toBe('failed')
        ->and($order->fresh()->paid_at)->toBeNull();

    // A second attempt (another provider/method) succeeds: failed -> paid.
    opAttempt($order, 'pi_op_b');
    $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_b', 'evt_b_ok'));

    expect(opOrderState($order))->toBe('paid')
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and(Payment::where('order_id', $order->id)->first()->status)->toBe(PaymentStatus::Paid);

    Event::assertDispatchedTimes(OrderTransitioned::class, 2); // pending->failed, failed->paid
});

it('a duplicate late failure signal for an earlier attempt can never downgrade a paid Order', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    $processor = app(PaymentEventProcessor::class);

    opAttempt($order, 'pi_op_a');
    $processor->apply(opEvent(ProviderEventType::Failed, 'pi_op_a', 'evt_a_fail'));
    opAttempt($order, 'pi_op_b');
    $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_b', 'evt_b_ok'));

    $paidAt = $order->fresh()->paid_at;

    // Redelivery of A's failure (same and different event id).
    $processor->apply(opEvent(ProviderEventType::Failed, 'pi_op_a', 'evt_a_fail'));
    $processor->apply(opEvent(ProviderEventType::Failed, 'pi_op_a', 'evt_a_fail_redelivered'));

    expect(opOrderState($order))->toBe('paid')
        ->and($order->fresh()->paid_at->equalTo($paidAt))->toBeTrue();

    Event::assertDispatchedTimes(OrderTransitioned::class, 2); // still only the two real transitions
});

it('a full refund moves a paid Order to refunded (terminal), recording refunded_at', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    $processor = app(PaymentEventProcessor::class);

    opAttempt($order, 'pi_op_r');
    $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_r', 'evt_r_ok'));
    $processor->apply(opEvent(ProviderEventType::Refunded, 'pi_op_r', 'evt_r_refund', [
        'reversalReference' => 're_op_r',
        'refundedAmountMinorUnits' => 4250,
    ]));

    expect(opOrderState($order))->toBe('refunded')
        ->and($order->fresh()->refunded_at)->not->toBeNull()
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and(Payment::where('order_id', $order->id)->first()->status)->toBe(PaymentStatus::Refunded);

    Event::assertDispatchedTimes(OrderTransitioned::class, 2); // pending->paid, paid->refunded
});

it('a partial refund — which is not supported — leaves the Order paid and the ledger untouched', function () {
    $order = opOrder();
    $processor = app(PaymentEventProcessor::class);

    opAttempt($order, 'pi_op_p');
    $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_p', 'evt_p_ok'));
    $processor->apply(opEvent(ProviderEventType::Refunded, 'pi_op_p', 'evt_p_partial', [
        'reversalReference' => 're_op_p',
        'refundedAmountMinorUnits' => 1000, // < 4250: partial
    ]));

    expect(opOrderState($order))->toBe('paid')
        ->and($order->fresh()->refunded_at)->toBeNull()
        ->and(StoreWalletTransaction::where('external_reference', 're_op_p')->exists())->toBeFalse();
});

it('fails closed — rolling the Wallet mutation back too — when the Order\'s state contradicts the financial fact being settled', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    opAttempt($order, 'pi_op_bad');

    // A corrupt Order: `refunded` (terminal) although its sale is still pending.
    DB::table('orders')->where('id', $order->id)->update(['order_status_id' => OrderStatus::bySlugOrFail('refunded')->id]);

    expect(fn () => app(PaymentEventProcessor::class)->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_bad', 'evt_bad')))
        ->toThrow(InvalidOrderTransitionException::class, "from 'refunded' to 'paid'");

    // Settlement did not half-apply: the sale is still pending, the balance untouched.
    $sale = StoreWalletTransaction::where('external_reference', 'pi_op_bad')->firstOrFail();

    expect($sale->status->slug)->toBe('pending')
        ->and($sale->storeWallet->fresh()->balance)->toBe('0.00')
        ->and(opOrderState($order))->toBe('refunded')
        ->and(PaymentAttempt::where('provider_reference', 'pi_op_bad')->first()->status)->toBe(PaymentAttemptStatus::Claimed);

    Event::assertNotDispatched(OrderTransitioned::class);
});

// ---------------------------------------------------------------------
// Transaction / after-commit behavior, proven through the REAL processor
// ---------------------------------------------------------------------

it('emits OrderTransitioned only at the OUTERMOST commit, after the Order transition is durable — never inside the settlement transaction', function () {
    $order = opOrder();
    opAttempt($order, 'pi_op_commit');
    $baseLevel = DB::transactionLevel();

    $observed = ['order_update_levels' => []];

    DB::listen(function ($query) use (&$observed) {
        if (str_contains($query->sql, 'update "orders"')) {
            $observed['order_update_levels'][] = DB::transactionLevel();
        }
    });

    Event::listen(OrderTransitioned::class, function () use (&$observed) {
        $observed['level_in_listener'] = DB::transactionLevel();
        $observed['state_seen_by_listener'] = DB::table('orders')
            ->join('order_statuses', 'order_statuses.id', '=', 'orders.order_status_id')
            ->value('order_statuses.slug');
    });

    app(PaymentEventProcessor::class)->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_commit', 'evt_commit'));

    // The Order UPDATE ran nested inside the processor's settlement transaction...
    expect($observed['order_update_levels'])->not->toBeEmpty()
        ->and(min($observed['order_update_levels']))->toBeGreaterThan($baseLevel);

    // ...but the listener ran only once that transaction had fully closed, and
    // saw the transition already durable.
    expect($observed['level_in_listener'])->toBe($baseLevel)
        ->and($observed['state_seen_by_listener'])->toBe('paid');
});

it('a failure AFTER the Order transition rolls back Order + Wallet + Payment atomically and discards OrderTransitioned', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    opAttempt($order, 'pi_op_late', withAttempt: false); // markSettled() reaches the attempt lookup last, after markPaid()
    $before = opFootprint($order, 'pi_op_late');

    expect(fn () => app(PaymentEventProcessor::class)->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_late', 'evt_late')))
        ->toThrow(PaymentAttemptNotFoundException::class);

    expect(opFootprint($order, 'pi_op_late'))->toBe($before)
        ->and($before['order'])->toBe('pending')
        ->and($before['sale'])->toBe('pending')
        ->and($before['wallet'])->toBe('0.00')
        ->and($before['payment'])->toBe('pending');

    Event::assertNotDispatched(OrderTransitioned::class);
});

it('a failure at the Payment-update step (after the Wallet confirm AND the Order transition) also rolls everything back', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    opAttempt($order, 'pi_op_payfail');
    $before = opFootprint($order, 'pi_op_payfail');

    Payment::updating(fn () => throw new RuntimeException('injected: Payment update failed'));

    try {
        expect(fn () => app(PaymentEventProcessor::class)->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_payfail', 'evt_payfail')))
            ->toThrow(RuntimeException::class, 'injected');
    } finally {
        Payment::flushEventListeners();
    }

    expect(opFootprint($order, 'pi_op_payfail'))->toBe($before)
        ->and($before['attempt'])->toBe('claimed');

    Event::assertNotDispatched(OrderTransitioned::class);
});

it('a synchronous listener that throws does so AFTER the durable commit: nothing is rolled back, and redelivery is a clean no-op', function () {
    $order = opOrder();
    opAttempt($order, 'pi_op_listener');
    $processor = app(PaymentEventProcessor::class);

    Event::listen(OrderTransitioned::class, fn () => throw new RuntimeException('listener failed'));

    expect(fn () => $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_listener', 'evt_listener')))
        ->toThrow(RuntimeException::class, 'listener failed');

    // The exception reached the caller, but the settlement is fully committed.
    expect(opFootprint($order, 'pi_op_listener'))->toBe([
        'order' => 'paid',
        'paid_at' => $order->fresh()->paid_at->toIso8601String(),
        'sale' => 'completed',
        'wallet' => '42.50',
        'payment' => 'paid',
        'attempt' => 'succeeded',
    ]);

    // The provider retries the (500'd) delivery: idempotent, no second transition, so the throwing listener is not even reached.
    expect($processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_listener', 'evt_listener')))
        ->toBe(EventApplicationOutcome::Applied);
});

// ---------------------------------------------------------------------
// Duplicate / late deliveries preserve idempotency (regression)
// ---------------------------------------------------------------------

it('duplicate success and refund deliveries emit one event per REAL transition and never rewrite a timestamp', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    $processor = app(PaymentEventProcessor::class);
    opAttempt($order, 'pi_op_dupes');

    $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_dupes', 'evt_dupe_ok'));
    $paidAt = $order->fresh()->paid_at;
    $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_dupes', 'evt_dupe_ok'));            // same delivery
    $processor->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_dupes', 'evt_dupe_ok_redelivered')); // new event id, same fact

    $refund = ['reversalReference' => 're_op_dupes', 'refundedAmountMinorUnits' => 4250];
    $processor->apply(opEvent(ProviderEventType::Refunded, 'pi_op_dupes', 'evt_dupe_refund', $refund));
    $refundedAt = $order->fresh()->refunded_at;
    $processor->apply(opEvent(ProviderEventType::Refunded, 'pi_op_dupes', 'evt_dupe_refund', $refund));
    $processor->apply(opEvent(ProviderEventType::Refunded, 'pi_op_dupes', 'evt_dupe_refund_redelivered', $refund));

    expect(opOrderState($order))->toBe('refunded')
        ->and($order->fresh()->paid_at->equalTo($paidAt))->toBeTrue()
        ->and($order->fresh()->refunded_at->equalTo($refundedAt))->toBeTrue()
        ->and(StoreWalletTransaction::where('external_reference', 're_op_dupes')->count())->toBe(1);

    Event::assertDispatchedTimes(OrderTransitioned::class, 2); // pending->paid, paid->refunded — nothing more
});

// ---------------------------------------------------------------------
// Corrupt Order: fail closed, and why atomic rollback is the right policy
// ---------------------------------------------------------------------

it('keeps a corrupt-Order settlement REPAIRABLE: the stored event survives the refusal, and once the Order is repaired the same replay settles', function () {
    Event::fake([OrderTransitioned::class]);
    $order = opOrder();
    opAttempt($order, 'pi_op_repair');
    DB::table('orders')->where('id', $order->id)->update(['order_status_id' => OrderStatus::bySlugOrFail('refunded')->id]); // corrupt
    PaymentProviderEvent::create([
        'provider' => 'stripe',
        'provider_event_id' => 'evt_op_repair',
        'event_type' => 'payment_intent.succeeded',
        'provider_reference' => 'pi_op_repair',
        'payload' => [
            'id' => 'evt_op_repair', 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_op_repair', 'object' => 'payment_intent', 'amount' => 4250, 'currency' => 'eur',
                'status' => 'succeeded', 'metadata' => ['order_id' => (string) $order->id], 'last_payment_error' => null,
            ]],
        ],
        'status' => ProviderEventStatus::Pending,
    ]);
    $processor = app(PaymentEventProcessor::class);

    expect(fn () => $processor->replayUnmatchedEvents('stripe', 'pi_op_repair'))
        ->toThrow(InvalidOrderTransitionException::class);

    // Nothing half-applied, and the event is still there to retry.
    $event = PaymentProviderEvent::where('provider_event_id', 'evt_op_repair')->firstOrFail();
    expect($event->status)->toBe(ProviderEventStatus::Pending)
        ->and($event->replay_attempts)->toBe(1)
        ->and($event->last_replay_error)->not->toBeNull()
        ->and(StoreWalletTransaction::where('external_reference', 'pi_op_repair')->first()->status->slug)->toBe('pending');

    // Operator repairs the Order out of band (no sanctioned repair path exists — see ORDER-LIFECYCLE.md §11).
    DB::table('orders')->where('id', $order->id)->update(['order_status_id' => OrderStatus::bySlugOrFail('pending')->id]);

    $processor->replayUnmatchedEvents('stripe', 'pi_op_repair');

    expect($event->fresh()->status)->toBe(ProviderEventStatus::Applied)
        ->and(opOrderState($order))->toBe('paid')
        ->and(opFootprint($order, 'pi_op_repair'))->toMatchArray(['sale' => 'completed', 'wallet' => '42.50', 'payment' => 'paid', 'attempt' => 'succeeded']);

    Event::assertDispatchedTimes(OrderTransitioned::class, 1);
});

// ---------------------------------------------------------------------
// E1 through the real processor: a morph map must never block settlement
// ---------------------------------------------------------------------

it('settles a payment whose historical sale holds the legacy class-name reference after a morph map is configured', function () {
    $order = opOrder();
    opAttempt($order, 'pi_op_legacy'); // written before any map: referenceable_type is the class name
    $before = DB::table('store_wallet_transactions')->where('external_reference', 'pi_op_legacy')->first();

    expect($before->referenceable_type)->toBe(Order::class);

    opWithMorphMap(['order' => Order::class], function () {
        app(PaymentEventProcessor::class)->apply(opEvent(ProviderEventType::Succeeded, 'pi_op_legacy', 'evt_legacy'));
    });

    expect(opOrderState($order))->toBe('paid')
        ->and(opFootprint($order, 'pi_op_legacy'))->toMatchArray(['sale' => 'completed', 'wallet' => '42.50', 'payment' => 'paid']);

    // Only the lifecycle state moved; the historical reference itself was never rewritten.
    expect(DB::table('store_wallet_transactions')->where('external_reference', 'pi_op_legacy')->value('referenceable_type'))->toBe(Order::class);
});
