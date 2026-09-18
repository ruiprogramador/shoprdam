<?php

use App\Domain\Orders\Enums\OrderLifecycleState;
use App\Domain\Orders\Events\OrderTransitioned;
use App\Domain\Orders\Exceptions\InvalidOrderTransitionException;
use App\Domain\Orders\Exceptions\OrderTransitionConflictException;
use App\Domain\Orders\Services\OrderLifecycleService;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentProviderEvent;
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
 * docs/financial/ORDER-LIFECYCLE.md — the canonical Order transition
 * boundary. Wallet rows are created ONLY through the real
 * WalletTransactionService (the sole ledger writer), so the "evidence" each
 * transition is authorized by is exactly what production produces — nothing
 * here fakes settlement.
 *
 * Concurrency note (mirrors INVARIANTS.md's own): the lock/CAS tests are
 * sequential simulations on SQLite, where `lockForUpdate()` is a no-op. They
 * prove the compare-and-set logic and the "decide from fresh state, never
 * the caller's stale instance" rule; they do NOT prove behavior under true
 * multi-connection parallelism on MySQL/PostgreSQL.
 */
function olOrder(string $amount = '42.50'): Order
{
    return Order::factory()->forStore(Store::factory()->create())->amount($amount)->create();
}

/** Creates the Order's own `sale` via the real Wallet service, ending in the given status. */
function olSale(Order $order, string $final = 'pending', string $suffix = 'a'): StoreWalletTransaction
{
    $wallets = app(WalletService::class);
    $service = app(WalletTransactionService::class);

    $sale = $service->record(
        wallet: $wallets->getOrCreateWallet($order->store, $order->currency->code),
        categorySlug: 'sale',
        amount: $order->amount,
        reference: new WalletTransactionReference('stripe', "pi_ol_{$order->id}_{$suffix}"),
        options: ['status' => 'pending', 'referenceable' => $order, 'source' => TransactionSource::Api],
    );

    return match ($final) {
        'completed' => $service->confirm($sale),
        'failed' => $service->markFailed($sale),
        default => $sale,
    };
}

function olRefund(StoreWalletTransaction $sale): StoreWalletTransaction
{
    return app(WalletTransactionService::class)->reverse(
        original: $sale,
        reversalCategorySlug: 'customer_refund',
        reference: new WalletTransactionReference('stripe', "re_ol_{$sale->id}"),
    );
}

function olState(Order $order): string
{
    return $order->fresh()->status->slug;
}

/**
 * Runs $fn with a morph map configured, and ALWAYS clears it afterwards — the
 * map is process-global static state and would otherwise leak into every other
 * test in the run.
 */
function olWithMorphMap(array $map, Closure $fn): mixed
{
    Relation::morphMap($map);

    try {
        return $fn();
    } finally {
        (new ReflectionProperty(Relation::class, 'morphMap'))->setValue(null, []);
    }
}

function olSet(Order $order, string $slug): void
{
    // Direct DB write on purpose: this is test *setup* placing an Order in a
    // precondition state, deliberately bypassing the boundary under test.
    DB::table('orders')->where('id', $order->id)->update(['order_status_id' => OrderStatus::bySlugOrFail($slug)->id]);
}

// ---------------------------------------------------------------------
// Allowed transitions (ORDER-02) + timestamps + events
// ---------------------------------------------------------------------

it('pending -> paid, backed by a completed sale: records paid_at and emits one OrderTransitioned', function () {
    Event::fake([OrderTransitioned::class]);
    $order = olOrder();
    $sale = olSale($order, 'completed');

    $result = app(OrderLifecycleService::class)->markPaid($order, $sale);

    expect($result->changed)->toBeTrue()
        ->and($result->from)->toBe(OrderLifecycleState::Pending)
        ->and($result->to)->toBe(OrderLifecycleState::Paid)
        ->and(olState($order))->toBe('paid')
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and($order->fresh()->refunded_at)->toBeNull();

    Event::assertDispatchedTimes(OrderTransitioned::class, 1);
    Event::assertDispatched(OrderTransitioned::class, fn ($e) => $e->orderId === $order->id && $e->from === 'pending' && $e->to === 'paid');
});

it('pending -> failed, backed by a failed sale: no timestamp (failed can be re-entered, so it has no stable one)', function () {
    $order = olOrder();
    $sale = olSale($order, 'failed');

    $result = app(OrderLifecycleService::class)->markPaymentFailed($order, $sale);

    expect($result->changed)->toBeTrue()
        ->and(olState($order))->toBe('failed')
        ->and($order->fresh()->paid_at)->toBeNull()
        ->and($order->fresh()->refunded_at)->toBeNull();
});

it('failed -> paid: a payment-attempt failure is not terminal — a later successful attempt still pays the Order (ORDER-06)', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);

    $service->markPaymentFailed($order, olSale($order, 'failed', 'a'));
    expect(olState($order))->toBe('failed');

    $result = $service->markPaid($order, olSale($order, 'completed', 'b'));

    expect($result->from)->toBe(OrderLifecycleState::Failed)
        ->and($result->changed)->toBeTrue()
        ->and(olState($order))->toBe('paid')
        ->and($order->fresh()->paid_at)->not->toBeNull();
});

it('paid -> refunded, backed by a completed full refund: records refunded_at and keeps paid_at', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);
    $sale = olSale($order, 'completed');
    $service->markPaid($order, $sale);
    $paidAt = $order->fresh()->paid_at;

    olRefund($sale);
    $result = $service->markRefunded($order, $sale);

    expect($result->changed)->toBeTrue()
        ->and(olState($order))->toBe('refunded')
        ->and($order->fresh()->refunded_at)->not->toBeNull()
        ->and($order->fresh()->paid_at->equalTo($paidAt))->toBeTrue();
});

// ---------------------------------------------------------------------
// Forbidden transitions + terminal state (ORDER-02 / ORDER-03)
// ---------------------------------------------------------------------

it('paid -> failed is refused: a late failure signal can never downgrade a paid Order', function () {
    Event::fake([OrderTransitioned::class]);
    $order = olOrder();
    $service = app(OrderLifecycleService::class);

    $earlierFailedSale = olSale($order, 'failed', 'a');
    $service->markPaid($order, olSale($order, 'completed', 'b'));
    Event::fake([OrderTransitioned::class]); // ignore the transition above

    expect(fn () => $service->markPaymentFailed($order, $earlierFailedSale))
        ->toThrow(InvalidOrderTransitionException::class, "cannot transition from 'paid' to 'failed'");

    expect(olState($order))->toBe('paid');
    Event::assertNotDispatched(OrderTransitioned::class);
});

it('pending -> refunded and failed -> refunded are refused — only a paid Order can be refunded', function (string $startState) {
    $order = olOrder();
    $sale = olSale($order, 'completed');
    olRefund($sale); // the financial fact exists; the Order was simply never paid
    olSet($order, $startState);

    expect(fn () => app(OrderLifecycleService::class)->markRefunded($order, $sale))
        ->toThrow(InvalidOrderTransitionException::class, "cannot transition from '{$startState}' to 'refunded'");

    expect(olState($order))->toBe($startState);
})->with(['pending', 'failed']);

it('refunded is terminal: nothing moves it to paid or failed', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);
    $sale = olSale($order, 'completed');
    $service->markPaid($order, $sale);
    olRefund($sale);
    $service->markRefunded($order, $sale);

    expect(fn () => $service->markPaid($order, $sale))
        ->toThrow(InvalidOrderTransitionException::class, "from 'refunded' to 'paid'");

    $failed = olSale($order, 'failed', 'z');
    expect(fn () => $service->markPaymentFailed($order, $failed))
        ->toThrow(InvalidOrderTransitionException::class, "from 'refunded' to 'failed'");

    expect(olState($order))->toBe('refunded');
});

// ---------------------------------------------------------------------
// Idempotency (ORDER-10)
// ---------------------------------------------------------------------

it('repeating a transition into the current state is an explicit no-op: no rewritten timestamp, no second event', function () {
    Event::fake([OrderTransitioned::class]);
    $order = olOrder();
    $service = app(OrderLifecycleService::class);
    $sale = olSale($order, 'completed');

    $service->markPaid($order, $sale);
    $paidAt = $order->fresh()->paid_at;

    $second = $service->markPaid($order, $sale);

    expect($second->changed)->toBeFalse()
        ->and($second->from)->toBe(OrderLifecycleState::Paid)
        ->and($second->to)->toBe(OrderLifecycleState::Paid)
        ->and($order->fresh()->paid_at->equalTo($paidAt))->toBeTrue();

    Event::assertDispatchedTimes(OrderTransitioned::class, 1);
});

it('a repeated failure and a repeated refund are equally idempotent', function () {
    Event::fake([OrderTransitioned::class]);
    $order = olOrder();
    $service = app(OrderLifecycleService::class);

    $failed = olSale($order, 'failed', 'a');
    $service->markPaymentFailed($order, $failed);
    expect($service->markPaymentFailed($order, olSale($order, 'failed', 'b'))->changed)->toBeFalse();

    $sale = olSale($order, 'completed', 'c');
    $service->markPaid($order, $sale);
    olRefund($sale);
    $service->markRefunded($order, $sale);
    $refundedAt = $order->fresh()->refunded_at;

    expect($service->markRefunded($order, $sale)->changed)->toBeFalse()
        ->and($order->fresh()->refunded_at->equalTo($refundedAt))->toBeTrue();

    Event::assertDispatchedTimes(OrderTransitioned::class, 3); // pending->failed, failed->paid, paid->refunded
});

// ---------------------------------------------------------------------
// ORDER-05: the Order cannot enter a state without the financial fact
// ---------------------------------------------------------------------

it('refuses to mark an Order paid on a sale that is not completed', function () {
    $order = olOrder();

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, olSale($order, 'pending')))
        ->toThrow(InvalidOrderTransitionException::class, "expected 'completed'");

    expect(olState($order))->toBe('pending')->and($order->fresh()->paid_at)->toBeNull();
});

it('refuses evidence belonging to a different Order, however settled it is', function () {
    $order = olOrder();
    $otherOrder = olOrder();

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, olSale($otherOrder, 'completed')))
        ->toThrow(InvalidOrderTransitionException::class, 'does not belong to Order');

    expect(olState($order))->toBe('pending');
});

it('refuses a refund reversal (or any non-sale row) presented as the sale evidence', function () {
    $order = olOrder();
    $sale = olSale($order, 'completed');
    $reversal = olRefund($sale);

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, $reversal))
        ->toThrow(InvalidOrderTransitionException::class, 'not an original sale');
});

it('refuses evidence that does not exist in the database', function () {
    $order = olOrder();

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, new StoreWalletTransaction))
        ->toThrow(InvalidOrderTransitionException::class, 'does not exist');
});

it('refuses to mark an Order failed on a sale that did not fail, and paid on one that failed', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);

    expect(fn () => $service->markPaymentFailed($order, olSale($order, 'completed', 'a')))
        ->toThrow(InvalidOrderTransitionException::class, "expected 'failed'");

    expect(fn () => $service->markPaid($order, olSale($order, 'failed', 'b')))
        ->toThrow(InvalidOrderTransitionException::class, "expected 'completed'");

    expect(olState($order))->toBe('pending');
});

it('refuses to mark an Order refunded when the sale has no completed customer_refund reversal', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);
    $sale = olSale($order, 'completed');
    $service->markPaid($order, $sale);

    expect(fn () => $service->markRefunded($order, $sale))
        ->toThrow(InvalidOrderTransitionException::class, 'no completed customer_refund reversal');

    expect(olState($order))->toBe('paid');
});

// ---------------------------------------------------------------------
// ORDER-01: no Eloquent status write; unknown states fail closed
// ---------------------------------------------------------------------

it('rejects EVERY Eloquent route to a status change — save, update, quiet saves, withoutEvents, associate, fill, push (ORDER-01, runtime half)', function (Closure $mutate) {
    $order = olOrder();
    $paid = OrderStatus::bySlugOrFail('paid');

    expect(fn () => $mutate(Order::find($order->id), $paid))->toThrow(LogicException::class, 'OrderLifecycleService');

    expect(olState($order))->toBe('pending');
})->with([
    'update()' => [fn (Order $o, OrderStatus $s) => $o->update(['order_status_id' => $s->id])],
    'forceFill()->save()' => [fn (Order $o, OrderStatus $s) => $o->forceFill(['order_status_id' => $s->id])->save()],
    'fill()->save()' => [fn (Order $o, OrderStatus $s) => $o->fill(['order_status_id' => $s->id])->save()],
    'attribute assignment + save()' => [function (Order $o, OrderStatus $s) {
        $o->order_status_id = $s->id;
        $o->save();
    }],
    'saveQuietly()' => [function (Order $o, OrderStatus $s) {
        $o->order_status_id = $s->id;
        $o->saveQuietly();
    }],
    'updateQuietly()' => [fn (Order $o, OrderStatus $s) => $o->updateQuietly(['order_status_id' => $s->id])],
    'Order::withoutEvents(update())' => [fn (Order $o, OrderStatus $s) => Order::withoutEvents(fn () => $o->update(['order_status_id' => $s->id]))],
    'status()->associate()->save()' => [function (Order $o, OrderStatus $s) {
        $o->status()->associate($s);
        $o->save();
    }],
    'status()->associate()->saveQuietly()' => [function (Order $o, OrderStatus $s) {
        $o->status()->associate($s);
        $o->saveQuietly();
    }],
    'push()' => [function (Order $o, OrderStatus $s) {
        $o->order_status_id = $s->id;
        $o->push();
    }],
]);

it('refuses a mixed update atomically: the status guard throws before ANY column is written', function () {
    $order = olOrder('10.00');

    expect(fn () => $order->update(['amount' => '99.00', 'order_status_id' => OrderStatus::bySlugOrFail('paid')->id]))
        ->toThrow(LogicException::class);

    expect($order->fresh()->amount)->toBe('10.00')->and(olState($order))->toBe('pending');
});

it('still lets every ordinary, non-status Eloquent update through — including the quiet variants', function () {
    $order = olOrder('10.00');

    $order->update(['amount' => '11.00']);
    expect($order->fresh()->amount)->toBe('11.00');

    $order->updateQuietly(['amount' => '12.00']);
    expect($order->fresh()->amount)->toBe('12.00');

    $order->amount = '13.00';
    $order->saveQuietly();
    expect($order->fresh()->amount)->toBe('13.00');

    Order::withoutEvents(fn () => $order->update(['amount' => '14.00']));
    expect($order->fresh()->amount)->toBe('14.00');

    expect($order->touch())->toBeTrue()
        ->and(olState($order))->toBe('pending');
});

it('leaves the canonical query-builder compare-and-set working alongside the guard, for every transition', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);
    $sale = olSale($order, 'completed');

    $service->markPaid($order, $sale);
    olRefund($sale);
    $service->markRefunded($order, $sale);

    expect(olState($order))->toBe('refunded')
        ->and($order->fresh()->paid_at)->not->toBeNull()
        ->and($order->fresh()->refunded_at)->not->toBeNull();
});

it('fails closed on an Order whose current state this code does not know', function () {
    $order = olOrder();
    OrderStatus::create(['name' => 'Accepted', 'slug' => 'accepted', 'sort_order' => 50]);
    olSet($order, 'accepted');

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, olSale($order, 'completed')))
        ->toThrow(InvalidOrderTransitionException::class, "unrecognized state ('accepted')");

    expect(olState($order))->toBe('accepted');
});

// ---------------------------------------------------------------------
// E1: ownership is morph-aware model identity, never a raw string compare
// ---------------------------------------------------------------------

it('settles an Order whose sale holds the LEGACY class-name reference even after a morph map is configured', function () {
    $order = olOrder();
    $sale = olSale($order, 'completed'); // written before any map: referenceable_type is the class name

    expect($sale->referenceable_type)->toBe(Order::class);

    olWithMorphMap(['order' => Order::class], function () use ($order, $sale) {
        // The Order's *current* morph class is now the alias — the raw strings differ.
        expect($order->getMorphClass())->toBe('order')
            ->and($sale->referenceable_type)->not->toBe($order->getMorphClass());

        $result = app(OrderLifecycleService::class)->markPaid($order, $sale);

        expect($result->changed)->toBeTrue()->and(olState($order))->toBe('paid');
    });
});

it('settles an Order whose sale holds a morph ALIAS written after the map was configured', function () {
    olWithMorphMap(['order' => Order::class], function () {
        $order = olOrder();
        $sale = olSale($order, 'completed');

        expect($sale->referenceable_type)->toBe('order');

        app(OrderLifecycleService::class)->markPaid($order, $sale);

        expect(olState($order))->toBe('paid');
    });
});

it('does not rewrite the historical financial row to make that work', function () {
    $order = olOrder();
    $sale = olSale($order, 'completed');
    $before = DB::table('store_wallet_transactions')->where('id', $sale->id)->first();

    olWithMorphMap(['order' => Order::class], fn () => app(OrderLifecycleService::class)->markPaid($order, $sale));

    expect(DB::table('store_wallet_transactions')->where('id', $sale->id)->first())->toEqual($before);
});

it('still refuses another Order\'s sale under a morph map, in either encoding', function () {
    $order = olOrder();
    $other = olOrder();
    $legacy = olSale($other, 'completed', 'a');

    olWithMorphMap(['order' => Order::class], function () use ($order, $other, $legacy) {
        $alias = olSale($other, 'completed', 'b');

        foreach ([$legacy, $alias] as $foreign) {
            expect(fn () => app(OrderLifecycleService::class)->markPaid($order, $foreign))
                ->toThrow(InvalidOrderTransitionException::class, 'does not belong to Order');
        }

        expect(olState($order))->toBe('pending');
    });
});

it('refuses a reference of the same id but a different model class, and one that resolves to no model at all — as a refused transition, never a class-not-found error', function (?string $type) {
    $order = olOrder();
    $sale = olSale($order, 'completed');
    DB::table('store_wallet_transactions')->where('id', $sale->id)->update(['referenceable_type' => $type]);

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, $sale))
        ->toThrow(InvalidOrderTransitionException::class, 'does not belong to Order');

    expect(olState($order))->toBe('pending');
})->with([
    'a different model with the same id' => [Store::class],
    'a class that does not exist' => ['App\\Models\\DoesNotExist'],
    'an unmapped alias' => ['nonsense'],
    'null' => [null],
    'empty' => [''],
]);

// ---------------------------------------------------------------------
// ORDER-11: stale instances, compare-and-set, rollback, commit ordering
// ---------------------------------------------------------------------

it('decides from the CURRENT database state, never the caller\'s stale instance', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);
    $failedSale = olSale($order, 'failed', 'a');
    $paidSale = olSale($order, 'completed', 'b');

    $stale = Order::find($order->id); // still says pending; pending -> failed would be allowed
    $stale->load('status');

    $service->markPaid(Order::find($order->id), $paidSale); // another process wins

    expect($stale->status->slug)->toBe('pending');

    expect(fn () => $service->markPaymentFailed($stale, $failedSale))
        ->toThrow(InvalidOrderTransitionException::class, "from 'paid' to 'failed'");

    expect(olState($order))->toBe('paid');
});

it('fails closed, without overwriting, when the status changes between the validated read and the write (compare-and-set)', function () {
    Event::fake([OrderTransitioned::class]);
    $order = olOrder();
    $sale = olSale($order, 'completed');
    $failedId = OrderStatus::bySlugOrFail('failed')->id;

    // A "racer" flips the Order to `failed` right after the service's evidence
    // read and before its UPDATE — exactly the window a lock would close on
    // MySQL/PostgreSQL and SQLite leaves open.
    $raced = false;
    DB::listen(function ($query) use (&$raced, $order, $failedId) {
        if ($raced || ! str_contains($query->sql, 'store_wallet_transactions')) {
            return;
        }
        $raced = true;
        DB::table('orders')->where('id', $order->id)->update(['order_status_id' => $failedId]);
    });

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, $sale))
        ->toThrow(OrderTransitionConflictException::class);

    // The conflict itself is the proof the CAS matched zero rows. Nothing the
    // transition would have written (status, paid_at) landed, and no event
    // was emitted. (This simulated racer shares the service's connection, so
    // its own write is rolled back with the failed transaction — a real racer
    // on another connection would have committed independently; the final
    // status is therefore deliberately not asserted here.)
    expect($raced)->toBeTrue()
        ->and($order->fresh()->paid_at)->toBeNull();

    Event::assertNotDispatched(OrderTransitioned::class);
});

it('emits its event only after the OUTER transaction commits, and never if that transaction rolls back', function () {
    Event::fake([OrderTransitioned::class]);
    $order = olOrder();
    $sale = olSale($order, 'completed');
    $service = app(OrderLifecycleService::class);

    // Rolled back after the transition succeeded — e.g. PaymentEventProcessor's
    // surrounding settlement transaction failing on a later step.
    try {
        DB::transaction(function () use ($service, $order, $sale) {
            $service->markPaid($order, $sale);
            Event::assertNotDispatched(OrderTransitioned::class); // not yet: still inside the transaction

            throw new RuntimeException('a later step in the surrounding transaction failed');
        });
    } catch (RuntimeException) {
    }

    expect(olState($order))->toBe('pending')
        ->and($order->fresh()->paid_at)->toBeNull();
    Event::assertNotDispatched(OrderTransitioned::class);

    // Same work, outer transaction commits: exactly one event, now.
    DB::transaction(fn () => $service->markPaid($order, $sale));

    Event::assertDispatchedTimes(OrderTransitioned::class, 1);
});

it('carries only scalar identifiers in its event — never a mutable model', function () {
    Event::fake([OrderTransitioned::class]);
    $order = olOrder();

    app(OrderLifecycleService::class)->markPaid($order, olSale($order, 'completed'));

    Event::assertDispatched(OrderTransitioned::class, function (OrderTransitioned $e) use ($order) {
        return $e->orderId === $order->id
            && is_string($e->from) && is_string($e->to)
            && $e->occurredAt instanceof DateTimeInterface;
    });
});

// ---------------------------------------------------------------------
// ORDER-04 / ORDER-12: the Order lifecycle never touches financial state
// ---------------------------------------------------------------------

it('never mutates the Wallet, creates a Payment, or fabricates a provider event — for any transition', function () {
    $order = olOrder();
    $service = app(OrderLifecycleService::class);
    $wallet = app(WalletService::class)->getOrCreateWallet($order->store, $order->currency->code);

    $sale = olSale($order, 'completed');
    olRefund($sale);
    // Wallet is now in its final financial state; from here only Order transitions run.
    $balance = $wallet->fresh()->balance;
    $transactions = StoreWalletTransaction::count();

    olSet($order, 'paid');
    $service->markRefunded($order, $sale);

    expect($wallet->fresh()->balance)->toBe($balance)
        ->and(StoreWalletTransaction::count())->toBe($transactions)
        ->and(Payment::count())->toBe(0)
        ->and(PaymentProviderEvent::count())->toBe(0);
});

it('cannot make an Order paid without the Wallet having already settled — Order state alone never implies payment', function () {
    $order = olOrder();
    $wallet = app(WalletService::class)->getOrCreateWallet($order->store, $order->currency->code);
    $sale = olSale($order, 'pending'); // unsettled: balance untouched

    expect(fn () => app(OrderLifecycleService::class)->markPaid($order, $sale))
        ->toThrow(InvalidOrderTransitionException::class);

    expect($wallet->fresh()->balance)->toBe('0.00')
        ->and(olState($order))->toBe('pending');
});
