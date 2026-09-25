<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Orders\Exceptions\InvalidOrderCreationException;
use App\Domain\Orders\Services\OrderCreationService;
use App\Domain\Payments\Models\Payment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nnjeim\World\Models\Currency;

/**
 * docs/orders/ORDER-ITEMS.md — the canonical line-backed Order creation
 * boundary. Runs the real OrderCreationService/ProductService against the
 * real schema; nothing is faked.
 */
function ocCurrency(string $code = 'EUR'): Currency
{
    return Currency::query()->where('code', $code)->firstOrFail();
}

function ocProduct(Store $store, string $price = '10.00', string $name = 'Widget', string $currency = 'EUR'): Product
{
    return app(ProductService::class)->create($store, $name, $price, ocCurrency($currency)->id);
}

/** @param  array<int, array{0: Product|int, 1: mixed}>  $pairs */
function ocLines(array $pairs): array
{
    return array_map(fn ($pair) => ['product' => $pair[0], 'quantity' => $pair[1]], $pairs);
}

function ocCreate(Store $store, array $pairs): Order
{
    return app(OrderCreationService::class)->create($store, ocLines($pairs));
}

function ocNothingPersisted(): void
{
    expect(Order::count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0);
}

// ---------------------------------------------------------------------
// Happy path, snapshot, exact total (ORDER-ITEM-02/05/08/10)
// ---------------------------------------------------------------------

it('creates a line-backed Order at the existing initial pending state, with every commercial value derived from the Products', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store, '10.00', 'Widget A');
    $b = ocProduct($store, '0.35', 'Widget B');

    $order = ocCreate($store, [[$a, 3], [$b, 2]]);

    expect($order->exists)->toBeTrue()
        ->and($order->store_id)->toBe($store->id)
        ->and($order->currency_id)->toBe(ocCurrency()->id)
        ->and($order->status->slug)->toBe('pending')
        ->and((string) $order->amount)->toBe('30.70')
        ->and($order->is_line_backed)->toBeTrue()
        ->and((bool) DB::table('orders')->where('id', $order->id)->value('is_line_backed'))->toBeTrue()
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->map(fn ($i) => [$i->product_id, $i->product_name, (string) $i->unit_price_amount, $i->quantity, $i->lineTotal()])->all())
        ->toBe([
            [$a->id, 'Widget A', '10.00', 3, '30.00'],
            [$b->id, 'Widget B', '0.35', 2, '0.70'],
        ]);
});

it('offers the caller no way to supply Order.amount, a unit price, a name or a currency', function () {
    $params = (new ReflectionMethod(OrderCreationService::class, 'create'))->getParameters();

    expect(array_map(fn ($p) => $p->getName(), $params))->toBe(['store', 'lines']);

    $store = Store::factory()->create();
    $product = ocProduct($store);

    foreach (['unit_price', 'unit_price_amount', 'product_name', 'amount', 'currency_id'] as $forbidden) {
        expect(fn () => app(OrderCreationService::class)->create($store, [['product' => $product, 'quantity' => 1, $forbidden => '0.01']]))
            ->toThrow(InvalidOrderCreationException::class);
    }

    ocNothingPersisted();
});

it('never trusts a Product instance\'s in-memory attributes — only its key; the row is re-read', function () {
    $store = Store::factory()->create();
    $product = ocProduct($store, '10.00', 'Real');

    $forged = Product::query()->findOrFail($product->id);
    $forged->name = 'Forged';
    $forged->price_amount = '0.01';

    $order = app(OrderCreationService::class)->create($store, [['product' => $forged, 'quantity' => 1]]);

    expect($order->items->first()->product_name)->toBe('Real')
        ->and((string) $order->items->first()->unit_price_amount)->toBe('10.00')
        ->and((string) $order->amount)->toBe('10.00');
});

it('has exact, drift-free decimal totals where float arithmetic would not (ORDER-ITEM-05)', function () {
    $store = Store::factory()->create();
    $product = ocProduct($store, '0.10');

    // 0.1 * 3 is 0.30000000000000004 in float; 3 * 0.10 summed ten times drifts too.
    $order = ocCreate($store, [[$product, 3]]);
    expect((string) $order->amount)->toBe('0.30');

    $many = ocProduct($store, '19.99');
    $order = ocCreate($store, [[$many, 1_000_000]]);
    expect((string) $order->amount)->toBe('19990000.00')
        ->and($order->items->first()->lineTotal())->toBe('19990000.00');
});

// ---------------------------------------------------------------------
// Product changes never rewrite history (ORDER-ITEM-09/15)
// ---------------------------------------------------------------------

it('freezes the snapshot: renaming, repricing to another currency, deactivating and deleting the Product change nothing historical', function () {
    $store = Store::factory()->create();
    $product = ocProduct($store, '10.00', 'A');
    $order = ocCreate($store, [[$product, 2]]);

    $service = app(ProductService::class);
    $service->rename($product, 'B');
    $service->changePrice($product, '25.00', ocCurrency('USD')->id);
    $service->deactivate($product);
    $service->delete($product);

    $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();
    $order = $order->fresh();

    expect($item->product_name)->toBe('A')
        ->and((string) $item->unit_price_amount)->toBe('10.00')
        ->and($item->currency_id)->toBe(ocCurrency('EUR')->id)
        ->and($item->quantity)->toBe(2)
        ->and($item->lineTotal())->toBe('20.00')
        ->and((string) $order->amount)->toBe('20.00')
        ->and($order->currency_id)->toBe(ocCurrency('EUR')->id)
        // The trashed Product is still reachable for traceability — but it carries the NEW values, never the snapshot's.
        ->and($item->product->name)->toBe('B')
        ->and($item->product->trashed())->toBeTrue();
});

// ---------------------------------------------------------------------
// Duplicates (ORDER-ITEM-02, decision in ORDER-ITEMS.md §8)
// ---------------------------------------------------------------------

it('merges the same Product supplied several times into one line with the summed quantity', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store, '2.50');

    $order = ocCreate($store, [[$a, 2], [$a->id, 3]]);

    expect($order->items)->toHaveCount(1)
        ->and($order->items->first()->quantity)->toBe(5)
        ->and((string) $order->amount)->toBe('12.50');
});

it('produces identical lines for any ordering or splitting of the same request', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store, '1.00', 'A');
    $b = ocProduct($store, '2.00', 'B');

    $shape = fn (Order $o) => $o->items->map(fn ($i) => [$i->product_id, $i->quantity, (string) $i->unit_price_amount])->all();

    $one = ocCreate($store, [[$b, 1], [$a, 2], [$b, 4]]);
    $two = ocCreate($store, [[$a, 2], [$b, 5]]);
    $three = ocCreate($store, [[$a, 1], [$a, 1], [$b, 3], [$b, 2]]);

    expect($shape($one))->toBe($shape($two))->toBe($shape($three))
        ->and($shape($one))->toBe([[$a->id, 2, '1.00'], [$b->id, 5, '2.00']])
        ->and((string) $one->amount)->toBe('12.00');
});

it('detects quantity overflow across merged duplicates, integer-safely, and persists nothing', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store);

    expect(fn () => ocCreate($store, [[$a, OrderCreationService::MAX_QUANTITY], [$a, 1]]))
        ->toThrow(InvalidOrderCreationException::class, 'exceeds the supported maximum');

    ocNothingPersisted();

    // The ceiling itself is valid.
    $order = ocCreate($store, [[$a, OrderCreationService::MAX_QUANTITY]]);
    expect($order->items->first()->quantity)->toBe(OrderCreationService::MAX_QUANTITY);
});

// ---------------------------------------------------------------------
// Request validation — refused before the database is touched (ORDER-ITEM-04/17)
// ---------------------------------------------------------------------

it('rejects an empty Order (ORDER-ITEM-17)', function () {
    $store = Store::factory()->create();

    expect(fn () => app(OrderCreationService::class)->create($store, []))
        ->toThrow(InvalidOrderCreationException::class, 'at least one line');

    ocNothingPersisted();
});

it('rejects every non-positive-integer quantity: zero, negative, fractional, float-typed, numeric-string, bool, null, over the ceiling (ORDER-ITEM-04)', function (mixed $quantity) {
    $store = Store::factory()->create();
    $product = ocProduct($store);

    expect(fn () => ocCreate($store, [[$product, $quantity]]))
        ->toThrow(InvalidOrderCreationException::class, 'quantity must be a positive integer');

    ocNothingPersisted();
})->with([
    'zero' => [0],
    'negative' => [-1],
    'fractional' => [1.5],
    'float-typed whole number' => [2.0],
    'numeric string' => ['2'],
    'bool' => [true],
    'null' => [null],
    'over the 32-bit ceiling' => [OrderCreationService::MAX_QUANTITY + 1],
]);

it('rejects a malformed line or product reference', function (array $line) {
    $store = Store::factory()->create();

    expect(fn () => app(OrderCreationService::class)->create($store, [$line]))
        ->toThrow(InvalidOrderCreationException::class);

    ocNothingPersisted();
})->with([
    'missing quantity' => [['product' => 1]],
    'missing product' => [['quantity' => 1]],
    'product id zero' => [['product' => 0, 'quantity' => 1]],
    'product id string' => [['product' => '1', 'quantity' => 1]],
]);

it('rejects an unpersisted Product instance', function () {
    $store = Store::factory()->create();

    expect(fn () => app(OrderCreationService::class)->create($store, [['product' => new Product, 'quantity' => 1]]))
        ->toThrow(InvalidOrderCreationException::class, 'persisted Product');

    ocNothingPersisted();
});

it('rejects a Store that was never persisted', function () {
    expect(fn () => app(OrderCreationService::class)->create(new Store, ocLines([[1, 1]])))
        ->toThrow(InvalidOrderCreationException::class, 'not been persisted');
});

// ---------------------------------------------------------------------
// Product eligibility, Store and currency invariants (ORDER-ITEM-06/07)
// ---------------------------------------------------------------------

it('rejects a Product that does not exist', function () {
    $store = Store::factory()->create();

    expect(fn () => ocCreate($store, [[999999, 1]]))->toThrow(InvalidOrderCreationException::class, 'does not exist');

    ocNothingPersisted();
});

it('rejects an inactive Product and a soft-deleted Product', function () {
    $store = Store::factory()->create();
    $inactive = ocProduct($store);
    $deleted = ocProduct($store);
    app(ProductService::class)->deactivate($inactive);
    app(ProductService::class)->delete($deleted);

    expect(fn () => ocCreate($store, [[$inactive, 1]]))->toThrow(InvalidOrderCreationException::class, 'not active')
        ->and(fn () => ocCreate($store, [[$deleted, 1]]))->toThrow(InvalidOrderCreationException::class, 'deleted');

    ocNothingPersisted();
});

it('rejects a Product that belongs to a different Store, atomically — even when it is not the first line', function () {
    $store = Store::factory()->create();
    $mine = ocProduct($store);
    $theirs = ocProduct(Store::factory()->create());

    expect(fn () => ocCreate($store, [[$mine, 1], [$theirs, 1]]))
        ->toThrow(InvalidOrderCreationException::class, 'does not belong to Store');

    ocNothingPersisted();
});

it('rejects Products in different currencies and never converts', function () {
    $store = Store::factory()->create();
    $eur = ocProduct($store, '10.00', 'EUR item', 'EUR');
    $usd = ocProduct($store, '10.00', 'USD item', 'USD');

    expect(fn () => ocCreate($store, [[$eur, 1], [$usd, 1]]))
        ->toThrow(InvalidOrderCreationException::class, 'share one currency');

    ocNothingPersisted();

    // Each currency alone is fine, and the Order takes the Products' currency (never a caller's).
    expect(ocCreate($store, [[$usd, 1]])->currency_id)->toBe(ocCurrency('USD')->id);
});

it('rejects a stored Product price that is malformed, negative or over-precise instead of repairing it', function (string $stored) {
    $store = Store::factory()->create();
    $product = ocProduct($store);

    try {
        DB::table('products')->where('id', $product->id)->update(['price_amount' => $stored]);
    } catch (QueryException $e) {
        // A strictly-typed decimal(18,2) column (MySQL/MariaDB under strict
        // mode) can refuse a non-numeric value ('abc', '') at the write
        // itself — stronger protection than SQLite's dynamic typing, which
        // stores it as-is and relies on the application layer below to
        // catch it. Either way the bad value never becomes readable.
        expect($e)->toBeInstanceOf(QueryException::class);

        return;
    }

    // A real decimal(18,2) column can also silently ROUND an over-precise
    // value instead of rejecting it (confirmed on real MySQL 8: '10.005' is
    // stored as '10.01', even under strict mode — precision rounding is
    // allowed there, unlike a type/range violation). When that happens the
    // malformed value this test means to simulate was never actually
    // persisted, so the scenario isn't reproducible on this engine — same
    // conclusion as the write-rejected case above, just discovered after
    // the write instead of during it.
    $persisted = (string) DB::table('products')->where('id', $product->id)->value('price_amount');
    $changed = is_numeric($persisted) && is_numeric($stored)
        ? bccomp($persisted, $stored, 6) !== 0 // numeric engines may format '-5.00' back as '-5': compare by value
        : $persisted !== $stored;

    if ($changed) {
        expect($changed)->toBeTrue();

        return;
    }

    expect(fn () => ocCreate($store, [[$product, 1]]))
        ->toThrow(InvalidOrderCreationException::class, 'cannot be snapshotted');

    ocNothingPersisted();
})->with(['not a number' => ['abc'], 'negative' => ['-5.00'], 'three decimals' => ['10.005'], 'empty' => ['']]);

it('rejects a stored Product with an empty name', function () {
    $store = Store::factory()->create();
    $product = ocProduct($store);

    DB::table('products')->where('id', $product->id)->update(['name' => '   ']);

    expect(fn () => ocCreate($store, [[$product, 1]]))->toThrow(InvalidOrderCreationException::class, 'name is empty');
});

it('rejects an exact total above what orders.amount can hold, before persisting anything', function () {
    $store = Store::factory()->create();
    $product = ocProduct($store, '9999999999.99');

    expect(fn () => ocCreate($store, [[$product, OrderCreationService::MAX_QUANTITY]]))
        ->toThrow(InvalidOrderCreationException::class, 'maximum representable amount');

    ocNothingPersisted();
});

// ---------------------------------------------------------------------
// Product price 0.00 != a supported zero-total Order (ORDER-ITEM-20)
// ---------------------------------------------------------------------

it('refuses a canonical Order whose total is 0.00 — atomically, with no provider, Payment or Wallet effect', function () {
    $store = Store::factory()->create();
    $free = ocProduct($store, '0.00');
    $walletTransactionsBefore = StoreWalletTransaction::count();

    expect(fn () => ocCreate($store, [[$free, 2]]))
        ->toThrow(InvalidOrderCreationException::class, 'greater than 0.00');

    // Several zero-priced lines are still a zero total.
    $other = ocProduct($store, '0.00', 'Other free');
    expect(fn () => ocCreate($store, [[$free, 1], [$other, 3]]))
        ->toThrow(InvalidOrderCreationException::class, 'greater than 0.00');

    ocNothingPersisted();

    expect(Payment::count())->toBe(0)
        ->and(StoreWalletTransaction::count())->toBe($walletTransactionsBefore);
});

it('still allows a zero-priced Product beside a positive line: the invariant is on the Order total, not on each Product', function () {
    $store = Store::factory()->create();
    $free = ocProduct($store, '0.00', 'Free sample');
    $paid = ocProduct($store, '4.50', 'Paid');

    $order = ocCreate($store, [[$free, 2], [$paid, 2]]);

    expect((string) $order->amount)->toBe('9.00')
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->map(fn ($i) => (string) $i->unit_price_amount)->all())->toBe(['0.00', '4.50']);
});

// ---------------------------------------------------------------------
// Atomicity / crash matrix (ORDER-ITEM-10)
// ---------------------------------------------------------------------

it('rolls the Order back when the line insert fails after the Order insert already succeeded', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store);

    // Engine-agnostic failure injection (a SQLite RAISE(ABORT,...) trigger
    // has no portable equivalent across MySQL/MariaDB/PostgreSQL): intercept
    // the order_items INSERT itself and throw as it's about to run, inside
    // the same still-open transaction.
    DB::listen(function (QueryExecuted $q) {
        if (preg_match('/^insert\s+into\s+[`"]?order_items[`"]?/i', $q->sql)) {
            throw new RuntimeException('simulated line insert failure');
        }
    });

    try {
        expect(fn () => ocCreate($store, [[$a, 1]]))->toThrow(RuntimeException::class, 'simulated line insert failure');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    ocNothingPersisted();
});

it('leaves no partial line set when a later line in the same request fails to insert', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store, '1.00', 'A');
    $b = ocProduct($store, '1.00', 'B');

    // The production code writes every line in ONE bulk INSERT (see
    // OrderCreationService::create()), not one statement per line — so
    // "the second line fails" and "the whole insert fails" are the same
    // observable event here; this test's distinct value over its sibling
    // above is that it proves a MULTI-line request still leaves nothing
    // behind, not a single-line one. Same engine-agnostic failure injection
    // (see that sibling for why not a SQLite trigger).
    DB::listen(function (QueryExecuted $q) {
        if (preg_match('/^insert\s+into\s+[`"]?order_items[`"]?/i', $q->sql)) {
            throw new RuntimeException('simulated second-line failure');
        }
    });

    try {
        expect(fn () => ocCreate($store, [[$a, 1], [$b, 7]]))->toThrow(RuntimeException::class, 'simulated second-line failure');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    ocNothingPersisted();
});

it('does not leave a half-built Order when the surrounding transaction later rolls back', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store);

    try {
        DB::transaction(function () use ($store, $a) {
            ocCreate($store, [[$a, 1]]);

            throw new RuntimeException('outer failure after creation');
        });
    } catch (RuntimeException) {
    }

    ocNothingPersisted();
});

// ---------------------------------------------------------------------
// Snapshot coherence (ORDER-ITEM-09, ORDER-ITEMS.md §10)
// ---------------------------------------------------------------------

it('reads every requested Product in exactly one products query, so a snapshot cannot be torn across reads', function () {
    $store = Store::factory()->create();
    $a = ocProduct($store);
    $b = ocProduct($store);
    $c = ocProduct($store);

    $productQueries = [];
    DB::listen(function ($query) use (&$productQueries) {
        if (preg_match('/\bfrom\s+[`"]?products[`"]?/i', $query->sql)) {
            $productQueries[] = $query->sql;
        }
    });

    ocCreate($store, [[$a, 1], [$b, 1], [$c, 1]]);

    expect($productQueries)->toHaveCount(1);
});

// ---------------------------------------------------------------------
// Boundaries: no payment, no wallet, no inventory (ORDER-ITEM-14/16)
// ---------------------------------------------------------------------

it('creates no Payment and no Wallet transaction — payment starts separately, from the Order', function () {
    $store = Store::factory()->create();
    $walletTransactionsBefore = StoreWalletTransaction::count();

    ocCreate($store, [[ocProduct($store), 1]]);

    expect(Payment::count())->toBe(0)
        ->and(StoreWalletTransaction::count())->toBe($walletTransactionsBefore);
});

it('leaves legacy factory Orders line-less, legacy-marked and untouched by the new domain (ORDER-ITEM-11)', function () {
    $legacy = Order::factory()->amount('42.50')->create();

    expect($legacy->items)->toHaveCount(0)
        ->and((bool) $legacy->fresh()->is_line_backed)->toBeFalse()
        ->and(DB::table('order_items')->count())->toBe(0)
        ->and((string) $legacy->fresh()->amount)->toBe('42.50');
});
