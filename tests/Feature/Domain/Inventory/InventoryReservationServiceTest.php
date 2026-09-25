<?php

use App\Domain\Catalog\Services\ProductService;
use App\Domain\Inventory\Enums\InventoryReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidReservationException;
use App\Domain\Inventory\Exceptions\InventoryIntegrityException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Services\InventoryIntegrityChecker;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Domain\Orders\Exceptions\OrderLineIntegrityException;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nnjeim\World\Models\Currency;

/**
 * docs/inventory/INVENTORY-RESERVATIONS.md — the canonical reservation
 * service against the real schema and the real OrderCreationService; nothing
 * is faked. Everything here runs on SQLite, which serializes writers: these
 * tests prove the ALGORITHM (single conditional UPDATE, compare-and-set,
 * all-or-nothing, deterministic order) and the state machine, NOT production
 * (MySQL) lock behavior — see §11 of the design document for the argument
 * that carries that and for what is not proven.
 */
function invService(): InventoryReservationService
{
    return app(InventoryReservationService::class);
}

function invReservationCount(): int
{
    return DB::table('inventory_reservations')->count();
}

/** Drives the private, single-reservation compare-and-set with a possibly STALE model — what a losing concurrent worker holds. */
function invTransition(InventoryReservation $reservation, InventoryReservationStatus $to): bool
{
    return (new ReflectionMethod(InventoryReservationService::class, 'transition'))
        ->invoke(invService(), $reservation, $to);
}

/** @return list<array{sql: string, bindings: array}> every SQL statement issued while $fn runs */
function invCaptureSql(Closure $fn): array
{
    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql) {
        $sql[] = ['sql' => $q->sql, 'bindings' => $q->bindings];
    });

    try {
        $fn();
    } finally {
        Event::forget(QueryExecuted::class);
    }

    return $sql;
}

// ---------------------------------------------------------------------
// A. basic reserve / B. insufficient stock  (INVENTORY-01, -04)
// ---------------------------------------------------------------------

it('A: reserves a line — physical stock is untouched, available drops, and the reservation is tied to its OrderItem and Inventory', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $inventoryId = inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    $reservations = invService()->reserve($order);

    expect($reservations)->toHaveCount(1)
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3])
        ->and($reservations->first()->status)->toBe(InventoryReservationStatus::Reserved)
        ->and($reservations->first()->quantity)->toBe(2)
        ->and($reservations->first()->inventory_id)->toBe($inventoryId)
        ->and($reservations->first()->order_item_id)->toBe($order->items->first()->id)
        ->and($reservations->first()->committed_at)->toBeNull()
        ->and($reservations->first()->released_at)->toBeNull()
        ->and(Inventory::query()->first()->available())->toBe(3);
});

it('B: refuses more than is available, with no partial effect', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 1);
    $order = inventoryOrder($store, [[$product, 2]]);

    expect(fn () => invService()->reserve($order))->toThrow(InsufficientStockException::class);

    expect(inventoryState($product))->toBe(['on_hand' => 1, 'reserved' => 0, 'available' => 1])
        ->and(invReservationCount())->toBe(0);
});

it('refuses a Product that has no Inventory record — stock is never assumed to exist', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $order = inventoryOrder($store, [[$product, 1]]);

    expect(fn () => invService()->reserve($order))->toThrow(InvalidReservationException::class, 'no Inventory');

    expect(invReservationCount())->toBe(0);
});

it('can reserve the exact remaining quantity but never one unit more', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 3);

    invService()->reserve(inventoryOrder($store, [[$product, 2]]));
    invService()->reserve(inventoryOrder($store, [[$product, 1]]));

    expect(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 3, 'available' => 0]);

    expect(fn () => invService()->reserve(inventoryOrder($store, [[$product, 1]])))->toThrow(InsufficientStockException::class);

    expect(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 3, 'available' => 0])
        ->and(invReservationCount())->toBe(2);
});

// ---------------------------------------------------------------------
// C. stock = 1, two buyers  (INVENTORY-02)
// ---------------------------------------------------------------------

it('C: with stock=1, two orders for one unit cannot both succeed — sequentially', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 1);
    $a = inventoryOrder($store, [[$product, 1]]);
    $b = inventoryOrder($store, [[$product, 1]]);

    $outcomes = [];

    foreach ([$a, $b] as $order) {
        try {
            invService()->reserve($order);
            $outcomes[] = 'reserved';
        } catch (InsufficientStockException) {
            $outcomes[] = 'refused';
        }
    }

    expect($outcomes)->toBe(['reserved', 'refused'])
        ->and(inventoryState($product))->toBe(['on_hand' => 1, 'reserved' => 1, 'available' => 0]);
});

it('C: the decision is made by the UPDATE, not by an earlier read — a competitor that takes the last unit between A\'s reads and A\'s write still wins the unit', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 1);
    $a = inventoryOrder($store, [[$product, 1]]);
    $b = inventoryOrder($store, [[$product, 1]]);

    // Fires right after A's own SELECT of the inventory row — the exact point
    // where a read-then-write implementation would have already concluded
    // "1 available" — and lets B run to completion before A writes.
    $competitor = null;
    $armed = true;

    DB::listen(function (QueryExecuted $q) use (&$armed, &$competitor, $b) {
        if ($armed && str_starts_with($q->sql, 'select') && preg_match('/\bfrom\s+[`"]?inventories[`"]?/i', $q->sql)) {
            $armed = false;
            $competitor = invService()->reserve($b);
        }
    });

    try {
        expect(fn () => invService()->reserve($a))->toThrow(InsufficientStockException::class);
    } finally {
        Event::forget(QueryExecuted::class);
    }

    // B, having taken the last unit, succeeded; A was refused by the predicate.
    expect($competitor)->toHaveCount(1)
        ->and($competitor->first()->status)->toBe(InventoryReservationStatus::Reserved);
});

it('C: reserving issues exactly one conditional UPDATE and never SELECTs a stock quantity (SQL shape of the guard)', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    $queries = invCaptureSql(fn () => invService()->reserve($order));
    $sql = array_column($queries, 'sql');

    $inventoryUpdates = array_values(array_filter($sql, fn ($s) => (bool) preg_match('/^update\s+[`"]?inventories[`"]?/i', $s)));
    $inventorySelects = array_filter($sql, fn ($s) => str_starts_with($s, 'select') && preg_match('/\bfrom\s+[`"]?inventories[`"]?/i', $s));

    expect($inventoryUpdates)->toHaveCount(1)
        ->and($inventoryUpdates[0])->toContain('= reserved_quantity + 2') // left side is quoted per-engine, right side (DB::raw) never is
        ->and($inventoryUpdates[0])->toContain('on_hand_quantity - reserved_quantity >= ?');

    foreach ($inventorySelects as $select) {
        expect($select)->not->toContain('on_hand_quantity')->not->toContain('reserved_quantity');
    }
});

// ---------------------------------------------------------------------
// D. idempotent reserve  (INVENTORY-03, -04)
// ---------------------------------------------------------------------

it('D: a repeated reserve returns the same reservations and holds the stock once', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    $first = invService()->reserve($order);
    $second = invService()->reserve($order);
    $third = invService()->reserve($order->fresh());

    expect($second->pluck('id')->all())->toBe($first->pluck('id')->all())
        ->and($third->pluck('id')->all())->toBe($first->pluck('id')->all())
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3])
        ->and(invReservationCount())->toBe(1);
});

it('D: a lost unique(order_item_id) race rolls the loser back completely and it then converges on one hold', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $inventoryId = inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    $item = $order->items->first();

    // Right after this call's read of existing reservations, a "competitor"
    // claims the same order item and bumps the counter — so this call's INSERT
    // hits unique(order_item_id). That failure must roll the whole attempt
    // back (competitor-in-the-same-transaction included, an artifact of one
    // connection) and the single retry must then complete cleanly.
    $armed = true;

    DB::listen(function (QueryExecuted $q) use (&$armed, $inventoryId, $item) {
        if ($armed && str_starts_with($q->sql, 'select') && preg_match('/\bfrom\s+[`"]?inventory_reservations[`"]?/i', $q->sql)) {
            $armed = false;
            DB::table('inventory_reservations')->insert([
                'inventory_id' => $inventoryId, 'order_item_id' => $item->id, 'quantity' => 2,
                'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('inventories')->where('id', $inventoryId)->update(['reserved_quantity' => 2]);
        }
    });

    try {
        $result = invService()->reserve($order);
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($armed)->toBeFalse()
        ->and($result)->toHaveCount(1)
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3])
        ->and(invReservationCount())->toBe(1)
        ->and((new InventoryIntegrityChecker)->audit())->toBe([]);
});

it('D: only the unique(order_item_id) claim is retried — any other database error propagates', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $inventoryId = inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 1]]);
    $itemId = $order->items->first()->id;

    $row = ['inventory_id' => $inventoryId, 'order_item_id' => $itemId, 'quantity' => 1, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()];
    DB::table('inventory_reservations')->insert($row);

    $classify = fn (QueryException $e) => (new ReflectionMethod(InventoryReservationService::class, 'isReservationIdentityViolation'))->invoke(invService(), $e);

    try {
        DB::table('inventory_reservations')->insert($row);
    } catch (QueryException $duplicate) {
    }

    try {
        DB::table('inventory_reservations')->insert([...$row, 'order_item_id' => 999999, 'quantity' => 0]);
    } catch (QueryException $other) {
    }

    expect($classify($duplicate))->toBeTrue()
        ->and($classify($other))->toBeFalse();
});

it('refuses to re-reserve an OrderItem whose reservation is already terminal', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    invService()->reserve($order);
    invService()->release($order);

    expect(fn () => invService()->reserve($order))->toThrow(InvalidReservationException::class, 'terminal');
    expect(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);

    invService()->reserve(inventoryOrder($store, [[$product, 1]]));
    invService()->commit(Order::query()->orderByDesc('id')->first());

    expect(fn () => invService()->reserve(Order::query()->orderByDesc('id')->first()))->toThrow(InvalidReservationException::class, 'terminal');
});

// ---------------------------------------------------------------------
// E. multi-line atomicity  (INVENTORY-05) and deterministic lock order
// ---------------------------------------------------------------------

it('E: a multi-line Order is all-or-nothing — when the LAST line lacks stock, the earlier line is not left reserved', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '10.00', 'B');
    inventorySeed($a, 5);
    inventorySeed($b, 1);
    $order = inventoryOrder($store, [[$a, 1], [$b, 2]]);

    expect(fn () => invService()->reserve($order))->toThrow(InsufficientStockException::class);

    expect(inventoryState($a))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5])
        ->and(inventoryState($b))->toBe(['on_hand' => 1, 'reserved' => 0, 'available' => 1])
        ->and(invReservationCount())->toBe(0);
});

it('E: the same holds when the FIRST line lacks stock', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '10.00', 'B');
    inventorySeed($a, 1);
    inventorySeed($b, 5);
    $order = inventoryOrder($store, [[$a, 2], [$b, 1]]);

    expect(fn () => invService()->reserve($order))->toThrow(InsufficientStockException::class);

    expect(inventoryState($a)['reserved'])->toBe(0)
        ->and(inventoryState($b)['reserved'])->toBe(0)
        ->and(invReservationCount())->toBe(0);
});

it('E: reserves every line of a multi-line Order when all have stock', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '5.00', 'B');
    inventorySeed($a, 5);
    inventorySeed($b, 5);

    $reservations = invService()->reserve(inventoryOrder($store, [[$a, 2], [$b, 3]]));

    expect($reservations)->toHaveCount(2)
        ->and(inventoryState($a)['reserved'])->toBe(2)
        ->and(inventoryState($b)['reserved'])->toBe(3);
});

it('S: an exception halfway through a multi-line reservation rolls every line back', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '10.00', 'B');
    inventorySeed($a, 5);
    inventorySeed($b, 5);
    $order = inventoryOrder($store, [[$a, 1], [$b, 1]]);

    $updates = 0;

    DB::listen(function (QueryExecuted $q) use (&$updates) {
        if (preg_match('/^update\s+[`"]?inventories[`"]?/i', $q->sql) && ++$updates === 2) {
            throw new RuntimeException('simulated crash after the first line was reserved');
        }
    });

    try {
        expect(fn () => invService()->reserve($order))->toThrow(RuntimeException::class, 'simulated crash');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($updates)->toBe(2)
        ->and(inventoryState($a)['reserved'])->toBe(0)
        ->and(inventoryState($b)['reserved'])->toBe(0)
        ->and(invReservationCount())->toBe(0);
});

it('locks inventory rows in ascending Inventory-id order regardless of the order lines\' own order', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A'); // product id 1 ...
    $b = inventoryProduct($store, '10.00', 'B'); // ... product id 2
    $inventoryB = inventorySeed($b, 5);          // but B's inventory row is created FIRST
    $inventoryA = inventorySeed($a, 5);

    expect($inventoryB)->toBeLessThan($inventoryA);

    $order = inventoryOrder($store, [[$a, 1], [$b, 1]]);

    $queries = invCaptureSql(fn () => invService()->reserve($order));

    $locked = collect($queries)
        ->filter(fn ($q) => (bool) preg_match('/^update\s+[`"]?inventories[`"]?/i', $q['sql']))
        ->map(fn ($q) => $q['bindings'][1]) // (updated_at, id, quantity)
        ->values()
        ->all();

    expect($locked)->toBe([$inventoryB, $inventoryA]);
});

it('composes with Order creation atomically: one outer transaction leaves neither Order nor reservation when stock is short', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 1);

    $checkout = fn (int $quantity) => DB::transaction(function () use ($store, $product, $quantity) {
        $order = inventoryOrder($store, [[$product, $quantity]]);
        invService()->reserve($order);

        return $order;
    });

    expect(fn () => $checkout(2))->toThrow(InsufficientStockException::class);

    expect(Order::count())->toBe(0)
        ->and(DB::table('order_items')->count())->toBe(0)
        ->and(invReservationCount())->toBe(0)
        ->and(inventoryState($product)['reserved'])->toBe(0);

    $order = $checkout(1);

    expect(Order::count())->toBe(1)
        ->and(inventoryState($product)['reserved'])->toBe(1)
        ->and($order->exists)->toBeTrue();
});

// ---------------------------------------------------------------------
// F. release  (INVENTORY-06)
// ---------------------------------------------------------------------

it('F: release gives the hold back once — repeated releases restore nothing more', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    $changed = [invService()->release($order), invService()->release($order), invService()->release($order)];

    $reservation = InventoryReservation::query()->first();

    expect($changed)->toBe([1, 0, 0])
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5])
        ->and($reservation->status)->toBe(InventoryReservationStatus::Released)
        ->and($reservation->released_at)->not->toBeNull()
        ->and($reservation->committed_at)->toBeNull();
});

it('F: a second worker holding a STALE `reserved` reservation cannot release again', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    $stale = InventoryReservation::query()->first();

    invService()->release($order);

    expect(invTransition($stale, InventoryReservationStatus::Released))->toBeFalse()
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);
});

it('F: release never lets availability exceed physical stock', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 3);
    $order = inventoryOrder($store, [[$product, 3]]);
    invService()->reserve($order);

    foreach (range(1, 4) as $_) {
        invService()->release($order);
    }

    expect(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3]);
});

// ---------------------------------------------------------------------
// G. commit  (INVENTORY-07)
// ---------------------------------------------------------------------

it('G: commit consumes physical stock exactly once — available is unchanged by commit itself', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    expect(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3]);

    $changed = [invService()->commit($order), invService()->commit($order), invService()->commit($order)];

    $reservation = InventoryReservation::query()->first();

    expect($changed)->toBe([1, 0, 0])
        ->and(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3])
        ->and($reservation->status)->toBe(InventoryReservationStatus::Committed)
        ->and($reservation->committed_at)->not->toBeNull()
        ->and($reservation->released_at)->toBeNull();
});

it('G: two workers that both read `reserved` cannot both consume stock', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    $workerA = InventoryReservation::query()->first();
    $workerB = InventoryReservation::query()->first();

    expect(invTransition($workerA, InventoryReservationStatus::Committed))->toBeTrue()
        ->and(invTransition($workerB, InventoryReservationStatus::Committed))->toBeFalse()
        ->and(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3]);
});

it('commits every line of a multi-line Order together', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '5.00', 'B');
    inventorySeed($a, 5);
    inventorySeed($b, 5);
    $order = inventoryOrder($store, [[$a, 2], [$b, 3]]);
    invService()->reserve($order);

    expect(invService()->commit($order))->toBe(2)
        ->and(inventoryState($a))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3])
        ->and(inventoryState($b))->toBe(['on_hand' => 2, 'reserved' => 0, 'available' => 2]);
});

it('commit and release on an Order that holds no reservations are refused, not silently ignored', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 1]]);

    expect(fn () => invService()->commit($order))->toThrow(InvalidReservationException::class, 'no inventory reservations')
        ->and(fn () => invService()->release($order))->toThrow(InvalidReservationException::class, 'no inventory reservations')
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);
});

// ---------------------------------------------------------------------
// H / I. terminal states  (INVENTORY-08, -09)
// ---------------------------------------------------------------------

it('H: a committed reservation can never be released — no stock effect', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);
    invService()->commit($order);

    expect(fn () => invService()->release($order))->toThrow(InvalidReservationException::class, 'cannot become released');

    expect(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3])
        ->and(InventoryReservation::query()->first()->status)->toBe(InventoryReservationStatus::Committed);
});

it('I: a released reservation can never be committed — refused, not silently consumed', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);
    invService()->release($order);

    expect(fn () => invService()->commit($order))->toThrow(InvalidReservationException::class, 'cannot become committed');

    expect(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5])
        ->and(InventoryReservation::query()->first()->status)->toBe(InventoryReservationStatus::Released);
});

it('the state machine allows exactly Reserved -> Committed and Reserved -> Released', function () {
    foreach (InventoryReservationStatus::cases() as $from) {
        foreach (InventoryReservationStatus::cases() as $to) {
            $allowed = $from === InventoryReservationStatus::Reserved
                && in_array($to, [InventoryReservationStatus::Committed, InventoryReservationStatus::Released], true);

            expect($from->canTransitionTo($to))->toBe($allowed, "{$from->value} -> {$to->value}");
        }
    }

    expect(InventoryReservationStatus::Committed->isTerminal())->toBeTrue()
        ->and(InventoryReservationStatus::Released->isTerminal())->toBeTrue()
        ->and(InventoryReservationStatus::Reserved->isTerminal())->toBeFalse();
});

// ---------------------------------------------------------------------
// J. commit vs release  (INVENTORY-10)
// ---------------------------------------------------------------------

it('J: when release wins, a commit that read the reservation as `reserved` is refused and has no stock effect', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    $loserView = InventoryReservation::query()->first(); // read as `reserved`

    invService()->release($order);                        // the winner

    expect(fn () => invTransition($loserView, InventoryReservationStatus::Committed))->toThrow(InvalidReservationException::class);

    expect(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5])
        ->and(InventoryReservation::query()->first()->status)->toBe(InventoryReservationStatus::Released);
});

it('J: when commit wins, a release that read the reservation as `reserved` is refused and has no stock effect', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    $loserView = InventoryReservation::query()->first();

    invService()->commit($order);

    expect(fn () => invTransition($loserView, InventoryReservationStatus::Released))->toThrow(InvalidReservationException::class);

    expect(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3])
        ->and(InventoryReservation::query()->first()->status)->toBe(InventoryReservationStatus::Committed);
});

it('J: a multi-line commit that meets one already-released line fails as a whole and consumes nothing', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '10.00', 'B');
    inventorySeed($a, 5);
    inventorySeed($b, 5);
    $order = inventoryOrder($store, [[$a, 1], [$b, 1]]);
    invService()->reserve($order);

    // Only the second line is released (something outside the service, e.g. a bug or a raw fix-up).
    $second = InventoryReservation::query()->orderByDesc('inventory_id')->first();
    invTransition($second, InventoryReservationStatus::Released);

    expect(fn () => invService()->commit($order))->toThrow(InvalidReservationException::class);

    expect(inventoryState($a))->toBe(['on_hand' => 5, 'reserved' => 1, 'available' => 4])
        ->and(InventoryReservation::query()->where('status', 'committed')->count())->toBe(0);
});

// ---------------------------------------------------------------------
// O. Product changes after reservation  (INVENTORY-15)
// ---------------------------------------------------------------------

it('O: renaming, repricing, re-currencying, deactivating and soft-deleting the Product change nothing about the reservation, and commit still works from the OrderItem snapshot', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    $before = InventoryReservation::query()->first()->only(['inventory_id', 'order_item_id', 'quantity', 'status']);

    $products = app(ProductService::class);
    $products->rename($product, 'Renamed');
    $products->changePrice($product, '999.00', Currency::query()->where('code', 'USD')->value('id'));
    $products->deactivate($product);
    $products->delete($product);

    expect(InventoryReservation::query()->first()->only(['inventory_id', 'order_item_id', 'quantity', 'status']))->toEqual($before)
        ->and(inventoryState($product->id))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3]);

    expect(invService()->commit($order))->toBe(1)
        ->and(inventoryState($product->id))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3]);
});

it('O: a Product whose stock is reserved cannot be hard-deleted — the database refuses', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);

    expect(fn () => DB::table('products')->where('id', $product->id)->delete())->toThrow(QueryException::class);
    expect(fn () => $product->forceDelete())->toThrow(LogicException::class);
});

// ---------------------------------------------------------------------
// P. legacy Orders  (INVENTORY-17)
// ---------------------------------------------------------------------

it('P: a legacy line-less Order gets no reservation, no stock effect, and nothing is fabricated', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $legacy = Order::factory()->forStore($store)->amount('10.00')->create();

    expect(fn () => invService()->reserve($legacy))->toThrow(InvalidReservationException::class, 'no lines');
    expect(fn () => invService()->commit($legacy))->toThrow(InvalidReservationException::class);
    expect(fn () => invService()->release($legacy))->toThrow(InvalidReservationException::class);

    expect(invReservationCount())->toBe(0)
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);
});

it('a line-backed Order whose amount no longer matches its lines is refused before any stock is touched', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    DB::table('order_items')->where('order_id', $order->id)->update(['quantity' => 3]);

    expect(fn () => invService()->reserve($order))->toThrow(OrderLineIntegrityException::class);

    expect(invReservationCount())->toBe(0)
        ->and(inventoryState($product)['reserved'])->toBe(0);
});

// ---------------------------------------------------------------------
// Q. deletes and history  (INVENTORY-18)
// ---------------------------------------------------------------------

it('Q: every inventory foreign key is RESTRICT and the database refuses to delete a parent that a reservation depends on', function () {
    $rules = fn (string $table) => collect(dbForeignKeyInfo($table))
        ->map(fn ($info) => [$info['table'], $info['rule']])->all();

    expect($rules('inventories'))->toEqual(['product_id' => ['products', 'RESTRICT']])
        ->and($rules('inventory_reservations'))->toEqual([
            'inventory_id' => ['inventories', 'RESTRICT'],
            'order_item_id' => ['order_items', 'RESTRICT'],
        ]);

    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $inventoryId = inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 1]]);
    invService()->reserve($order);

    expect(fn () => DB::table('inventories')->where('id', $inventoryId)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_items')->where('order_id', $order->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->where('id', $order->id)->delete())->toThrow(QueryException::class)
        ->and(invReservationCount())->toBe(1);
});

it('terminal reservations survive as evidence — release/commit never delete a row', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $a = inventoryOrder($store, [[$product, 1]]);
    $b = inventoryOrder($store, [[$product, 1]]);
    invService()->reserve($a);
    invService()->reserve($b);
    invService()->release($a);
    invService()->commit($b);

    expect(InventoryReservation::query()->orderBy('id')->pluck('status')->map->value->all())->toBe(['released', 'committed']);
});

// ---------------------------------------------------------------------
// R. direct mutation attempts
// ---------------------------------------------------------------------

it('R: no Eloquent instance write path can change stock or a reservation', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 1]]);
    invService()->reserve($order);

    $inventory = Inventory::query()->first();
    $reservation = InventoryReservation::query()->first();

    $attempts = [
        'inventory save' => fn () => tap($inventory, fn ($i) => $i->on_hand_quantity = 999)->save(),
        'inventory saveQuietly' => fn () => tap($inventory, fn ($i) => $i->on_hand_quantity = 999)->saveQuietly(),
        'inventory update' => fn () => $inventory->update(['on_hand_quantity' => 999]),
        'inventory increment' => fn () => $inventory->increment('on_hand_quantity', 5),
        'inventory decrement' => fn () => $inventory->decrement('on_hand_quantity', 5),
        'inventory delete' => fn () => $inventory->delete(),
        'inventory forceCreate' => fn () => Inventory::forceCreate(['product_id' => $product->id, 'on_hand_quantity' => 1]),
        'inventory withoutEvents' => fn () => Inventory::withoutEvents(fn () => tap($inventory, fn ($i) => $i->on_hand_quantity = 999)->save()),
        'reservation status assignment' => fn () => tap($reservation, fn ($r) => $r->status = InventoryReservationStatus::Committed)->save(),
        'reservation update' => fn () => $reservation->update(['status' => 'released']),
        'reservation increment' => fn () => $reservation->increment('quantity'),
        'reservation delete' => fn () => $reservation->delete(),
        'reservation forceCreate' => fn () => InventoryReservation::forceCreate(['status' => 'reserved']),
    ];

    // Refused either by the model's own guard (LogicException) or, for the
    // mass-assignment shapes, earlier still by the empty $fillable.
    foreach ($attempts as $label => $attempt) {
        $refusal = null;

        try {
            $attempt();
        } catch (LogicException|MassAssignmentException $e) {
            $refusal = $e;
        }

        expect($refusal)->not->toBeNull("{$label} was not refused");
    }

    expect(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 1, 'available' => 4])
        ->and(InventoryReservation::query()->first()->status)->toBe(InventoryReservationStatus::Reserved);
});

// ---------------------------------------------------------------------
// Tampering / drift  (INVENTORY-20)
// ---------------------------------------------------------------------

it('quantity tampering: a reservation whose quantity no longer matches its OrderItem cannot be committed or released', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    DB::table('inventory_reservations')->update(['quantity' => 1]);

    expect(fn () => invService()->commit($order))->toThrow(InventoryIntegrityException::class, 'quantity')
        ->and(fn () => invService()->release($order))->toThrow(InventoryIntegrityException::class)
        ->and(fn () => invService()->reserve($order))->toThrow(InventoryIntegrityException::class);

    expect(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3])
        ->and(InventoryReservation::query()->first()->status)->toBe(InventoryReservationStatus::Reserved);
});

it('reservation row tampering: a reservation re-pointed at another Product\'s Inventory is refused', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '10.00', 'B');
    inventorySeed($a, 5);
    $inventoryB = inventorySeed($b, 5);
    $order = inventoryOrder($store, [[$a, 1]]);
    invService()->reserve($order);

    DB::table('inventory_reservations')->update(['inventory_id' => $inventoryB]);

    expect(fn () => invService()->commit($order))->toThrow(InventoryIntegrityException::class, 'Inventory');

    expect(inventoryState($b))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);
});

it('counter drift: a release/commit the counters cannot absorb fails closed and rolls the state transition back', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    invService()->reserve($order);

    // Raw write: the counter loses the hold while the reservation row still claims it.
    DB::table('inventories')->update(['reserved_quantity' => 0]);

    expect(fn () => invService()->release($order))->toThrow(InventoryIntegrityException::class, 'drifted');
    expect(fn () => invService()->commit($order))->toThrow(InventoryIntegrityException::class, 'drifted');

    // The CAS was rolled back with the failed counter update: still `reserved`, counters untouched.
    expect(InventoryReservation::query()->first()->status)->toBe(InventoryReservationStatus::Reserved)
        ->and(inventoryState($product)['reserved'])->toBe(0)
        ->and(collect((new InventoryIntegrityChecker)->audit())->pluck('code')->all())
        ->toContain(InventoryIntegrityChecker::COUNTER_DISAGREES_WITH_RESERVATIONS);
});
