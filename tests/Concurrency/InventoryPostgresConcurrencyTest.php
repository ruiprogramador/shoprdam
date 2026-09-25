<?php

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidReservationException;
use App\Domain\Inventory\Services\InventoryIntegrityChecker;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REAL-ENGINE concurrency proof for the inventory reservation core, the
 * PostgreSQL sibling of InventoryMysqlConcurrencyTest.php (see that file's
 * header for the full scenario catalogue and protocol description; the two
 * are line-for-line equivalent test-by-test, sharing worker.php and
 * ConcurrencyHelpers.php verbatim).
 *
 * The one behavioral difference this file asserts rather than assumes:
 * PostgreSQL's default `transaction_isolation` is READ COMMITTED, not
 * MySQL's REPEATABLE READ. The reservation core relies on explicit row
 * locking (SELECT ... FOR UPDATE via lockForUpdate()), not on snapshot
 * semantics, so this difference is not expected to change any outcome here
 * — this suite is what actually proves that, rather than inferring it from
 * the MySQL run.
 *
 * Lock-wait detection differs from MySQL: PostgreSQL exposes it directly on
 * pg_stat_activity.wait_event_type, no join needed (no performance_schema
 * equivalent). PostgreSQL also has no `innodb_lock_wait_timeout`-like cap by
 * default (`lock_timeout` is 0 = wait forever); a genuine deadlock is instead
 * caught by PostgreSQL's own deadlock detector (default deadlock_timeout=1s)
 * and aborts one side with SQLSTATE 40P01. The app's fixed ascending-id lock
 * order (proven deadlock-free below, same as MySQL) means this is not
 * expected to fire, but the harness does not rely on any lock-wait ceiling
 * from the server either way — it always ends every held lock itself via the
 * `.commit` signal file.
 *
 * Run:
 *
 *   INVENTORY_POSTGRES_CONCURRENCY=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=… \
 *   DB_DATABASE=<name>_concurrency_test DB_USERNAME=… DB_PASSWORD=… \
 *   [INVENTORY_CONCURRENCY_WORKER_PHP_ARGS="-d extension=pdo_pgsql"] \
 *   php vendor/pestphp/pest/bin/pest tests/Concurrency/InventoryPostgresConcurrencyTest.php
 *
 * against a database that has been `migrate`d. It refuses any database whose
 * name does not end in `_concurrency_test` or whose host is not local.
 */
require __DIR__.'/ConcurrencyHelpers.php';

beforeEach(function () {
    if (getenv('INVENTORY_POSTGRES_CONCURRENCY') !== '1') {
        $this->markTestSkipped('Set INVENTORY_POSTGRES_CONCURRENCY=1 and point DB_* at a disposable local PostgreSQL *_concurrency_test database.');
    }

    $connection = config('database.default');
    $settings = config("database.connections.{$connection}");

    if ($connection !== 'pgsql' || ! in_array($settings['host'], ['127.0.0.1', 'localhost'], true) || ! str_ends_with((string) $settings['database'], '_concurrency_test')) {
        throw new RuntimeException('Refusing to run: the effective connection is not a disposable local PostgreSQL *_concurrency_test database.');
    }
});

/** Whether the given PostgreSQL backend pid is currently waiting on a lock held by another transaction. */
function mcAwaitLockWait(int $backendPid, float $seconds = 30.0): bool
{
    $deadline = microtime(true) + $seconds;

    do {
        $waiting = DB::selectOne(
            "select count(*) as n from pg_stat_activity where pid = ? and wait_event_type = 'Lock'",
            [$backendPid],
        )->n;

        if ($waiting > 0) {
            return true;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    return false;
}

// ---------------------------------------------------------------------
// The real engine, and the schema on it
// ---------------------------------------------------------------------

it('runs on real PostgreSQL at its actual default isolation level (READ COMMITTED, not MySQL\'s REPEATABLE READ)', function () {
    $server = DB::selectOne("select version() as v, current_setting('transaction_isolation') as i, current_setting('lock_timeout') as t");

    expect($server->i)->toBe('read committed')
        ->and(DB::getDriverName())->toBe('pgsql');

    fwrite(STDERR, "\n[pgsql] {$server->v} isolation={$server->i} lock_timeout={$server->t}\n");
});

it('enforces the CHECK constraints and RESTRICT foreign keys on PostgreSQL itself, not only the SQLite triggers', function () {
    $checks = array_column(DB::select(
        "select constraint_name as name from information_schema.check_constraints where constraint_schema = 'public'",
    ), 'name');

    expect($checks)->toContain(
        'inventories_on_hand_non_negative',
        'inventories_reserved_non_negative',
        'inventories_reserved_within_on_hand',
        'inventory_reservations_quantity_positive',
        'inventory_reservations_status_evidence',
    );

    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $inventoryId = inventorySeed($product, 5, 2);
    $order = inventoryOrder($store, [[$product, 1]]);
    $itemId = $order->items->first()->id;
    $row = ['inventory_id' => $inventoryId, 'order_item_id' => $itemId, 'quantity' => 1, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()];

    $refused = [
        'negative on_hand' => fn () => inventorySeed(inventoryProduct($store), -1),
        'reserved above on_hand (insert)' => fn () => inventorySeed(inventoryProduct($store), 1, 2),
        'reserved above on_hand (update)' => fn () => DB::table('inventories')->where('id', $inventoryId)->update(['reserved_quantity' => 6]),
        'unknown status' => fn () => DB::table('inventory_reservations')->insert([...$row, 'status' => 'expired']),
        'committed without evidence' => fn () => DB::table('inventory_reservations')->insert([...$row, 'status' => 'committed']),
        'zero quantity' => fn () => DB::table('inventory_reservations')->insert([...$row, 'quantity' => 0]),
        'delete a referenced Inventory' => fn () => (function () use ($inventoryId, $row) {
            DB::table('inventory_reservations')->insert($row);
            DB::table('inventories')->where('id', $inventoryId)->delete();
        })(),
        'second reservation for one OrderItem' => fn () => DB::table('inventory_reservations')->insert($row),
    ];

    $sqlStates = [];

    try {
        foreach ($refused as $label => $attempt) {
            expect($attempt)->toThrow(QueryException::class);
        }

        try {
            DB::table('inventories')->where('id', $inventoryId)->update(['reserved_quantity' => 6]);
        } catch (QueryException $e) {
            $sqlStates[] = $e->errorInfo[0];
        }
    } finally {
        DB::table('inventory_reservations')->where('inventory_id', $inventoryId)->delete();
        DB::table('inventories')->where('id', $inventoryId)->delete();
    }

    expect($sqlStates)->toBe(['23514']); // check_violation — the constraint is really enforced
});

// ---------------------------------------------------------------------
// 1. stock = 1, two buyers, DIFFERENT Orders
// ---------------------------------------------------------------------

it('stock=1, two different Orders: B is genuinely blocked behind A\'s row lock, then refused — exactly one reservation', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 1);
    $orderA = inventoryOrder($store, [[$product, 1]]);
    $orderB = inventoryOrder($store, [[$product, 1]]);
    $dir = mcDir();

    $a = mcSpawn($dir, 'A', 'reserve', $orderA, hold: true);
    $b = mcSpawn($dir, 'B', 'reserve', $orderB);
    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('B never blocked on a lock')
        ->and(file_exists("{$dir}/B.result"))->toBeFalse();

    mcSignal($dir, 'A.commit');

    $ra = mcResult($dir, 'A');
    $rb = mcResult($dir, 'B');
    mcJoin($a, $b);

    expect($ra['status'])->toBe('ok')
        ->and($rb['status'])->toBe('error')
        ->and($rb['class'])->toBe(InsufficientStockException::class)
        ->and($rb['finished_at'])->toBeGreaterThanOrEqual($ra['finished_at'])
        ->and(inventoryState($product))->toBe(['on_hand' => 1, 'reserved' => 1, 'available' => 0])
        ->and(mcStatuses($orderA))->toBe(['reserved'])
        ->and(mcStatuses($orderB))->toBe([])
        ->and(DB::table('inventory_reservations')->where('status', 'reserved')->whereIn('order_item_id', $orderA->items->pluck('id')->merge($orderB->items->pluck('id')))->count())->toBe(1);
});

it('stock=1, four Orders released from a barrier over many rounds: exactly one wins every time, never zero, never two', function () {
    $store = Store::factory()->create();

    foreach (range(1, 10) as $round) {
        $product = inventoryProduct($store);
        inventorySeed($product, 1);
        $orders = array_map(fn () => inventoryOrder($store, [[$product, 1]]), range(1, 4));

        $results = mcRace(collect($orders)->map(fn ($o, $i) => ['name' => "W{$i}", 'action' => 'reserve', 'order' => $o])->all());
        $outcomes = array_values(mcOutcomes($results));

        expect(collect($outcomes)->countBy()->all())->toEqual(['ok' => 1, InsufficientStockException::class => 3], "round {$round}: ".json_encode($outcomes))
            ->and(inventoryState($product))->toBe(['on_hand' => 1, 'reserved' => 1, 'available' => 0]);
    }
});

// ---------------------------------------------------------------------
// 2. duplicate concurrent reserve() of the SAME Order
// ---------------------------------------------------------------------

it('duplicate concurrent reserve() of one Order: the loser waits on the unique claim, converges on the winner\'s rows, and stock is held once', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    $dir = mcDir();

    $a = mcSpawn($dir, 'A', 'reserve', $order, hold: true);
    $b = mcSpawn($dir, 'B', 'reserve', $order);
    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('B never blocked on the unique claim');

    mcSignal($dir, 'A.commit');

    $ra = mcResult($dir, 'A');
    $rb = mcResult($dir, 'B');
    mcJoin($a, $b);

    expect($ra['status'])->toBe('ok')
        ->and($rb['status'])->toBe('ok')
        ->and($rb['value'])->toBe($ra['value'])
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3])
        ->and(mcStatuses($order))->toBe(['reserved']);
});

it('three workers reserve the same Order from a barrier over many rounds: all converge, one reservation, held once', function () {
    $store = Store::factory()->create();

    foreach (range(1, 8) as $round) {
        $product = inventoryProduct($store);
        inventorySeed($product, 5);
        $order = inventoryOrder($store, [[$product, 2]]);

        $results = mcRace(collect(range(0, 2))->map(fn ($i) => ['name' => "W{$i}", 'action' => 'reserve', 'order' => $order])->all());

        expect(array_values(mcOutcomes($results)))->toBe(['ok', 'ok', 'ok'], "round {$round}: ".json_encode($results))
            ->and(collect($results)->pluck('value')->unique(strict: false)->count())->toBe(1)
            ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3])
            ->and(mcStatuses($order))->toBe(['reserved']);
    }
});

// ---------------------------------------------------------------------
// 3. commit() vs release() on the same reservation
// ---------------------------------------------------------------------

it('commit vs release: while commit holds the reservation row, release blocks and is then refused — commit wins, one stock effect', function () {
    $store = Store::factory()->create();
    [$order, $product] = mcReservedOrder($store, 5, 2);
    $dir = mcDir();

    $a = mcSpawn($dir, 'A', 'commit', $order, hold: true);
    $b = mcSpawn($dir, 'B', 'release', $order);
    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('release never blocked behind commit');

    mcSignal($dir, 'A.commit');

    $ra = mcResult($dir, 'A');
    $rb = mcResult($dir, 'B');
    mcJoin($a, $b);

    expect($ra)->toMatchArray(['status' => 'ok', 'value' => 1])
        ->and($rb['class'])->toBe(InvalidReservationException::class)
        ->and(mcStatuses($order))->toBe(['committed'])
        ->and(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3]);
});

it('release vs commit: while release holds the reservation row, commit blocks and is then refused — release wins, no stock consumed', function () {
    $store = Store::factory()->create();
    [$order, $product] = mcReservedOrder($store, 5, 2);
    $dir = mcDir();

    $a = mcSpawn($dir, 'A', 'release', $order, hold: true);
    $b = mcSpawn($dir, 'B', 'commit', $order);
    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('commit never blocked behind release');

    mcSignal($dir, 'A.commit');

    $ra = mcResult($dir, 'A');
    $rb = mcResult($dir, 'B');
    mcJoin($a, $b);

    expect($ra)->toMatchArray(['status' => 'ok', 'value' => 1])
        ->and($rb['class'])->toBe(InvalidReservationException::class)
        ->and(mcStatuses($order))->toBe(['released'])
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);
});

it('commit and release released from a barrier over many rounds: exactly one terminal winner, exactly its stock effect', function () {
    $store = Store::factory()->create();

    foreach (range(1, 10) as $round) {
        [$order, $product] = mcReservedOrder($store, 5, 2);

        $results = mcRace([
            ['name' => 'C', 'action' => 'commit', 'order' => $order],
            ['name' => 'R', 'action' => 'release', 'order' => $order],
        ]);

        $outcomes = mcOutcomes($results);
        $status = mcStatuses($order);
        $expected = $status === ['committed']
            ? ['on_hand' => 3, 'reserved' => 0, 'available' => 3]
            : ['on_hand' => 5, 'reserved' => 0, 'available' => 5];

        expect(collect($outcomes)->countBy()->all())->toEqual(['ok' => 1, InvalidReservationException::class => 1], "round {$round}: ".json_encode($results))
            ->and($status)->toBeIn([['committed'], ['released']])
            ->and(inventoryState($product))->toBe($expected);
    }
});

// ---------------------------------------------------------------------
// 4/5. duplicate concurrent commit() and release()
// ---------------------------------------------------------------------

it('duplicate concurrent commit(): the second blocks, then is an idempotent no-op — stock consumed once', function () {
    $store = Store::factory()->create();
    [$order, $product] = mcReservedOrder($store, 5, 2);
    $dir = mcDir();

    $a = mcSpawn($dir, 'A', 'commit', $order, hold: true);
    $b = mcSpawn($dir, 'B', 'commit', $order);
    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('the duplicate commit never blocked');

    mcSignal($dir, 'A.commit');

    $ra = mcResult($dir, 'A');
    $rb = mcResult($dir, 'B');
    mcJoin($a, $b);

    expect([$ra['value'], $rb['value']])->toBe([1, 0])
        ->and(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3]);
});

it('duplicate concurrent release(): the second blocks, then is an idempotent no-op — restored once', function () {
    $store = Store::factory()->create();
    [$order, $product] = mcReservedOrder($store, 5, 2);
    $dir = mcDir();

    $a = mcSpawn($dir, 'A', 'release', $order, hold: true);
    $b = mcSpawn($dir, 'B', 'release', $order);
    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('the duplicate release never blocked');

    mcSignal($dir, 'A.commit');

    $ra = mcResult($dir, 'A');
    $rb = mcResult($dir, 'B');
    mcJoin($a, $b);

    expect([$ra['value'], $rb['value']])->toBe([1, 0])
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 0, 'available' => 5]);
});

it('three workers commit (and three release) the same Order from a barrier over many rounds: one stock effect each time', function () {
    $store = Store::factory()->create();

    foreach (['commit' => ['on_hand' => 3, 'reserved' => 0, 'available' => 3], 'release' => ['on_hand' => 5, 'reserved' => 0, 'available' => 5]] as $action => $expected) {
        foreach (range(1, 6) as $round) {
            [$order, $product] = mcReservedOrder($store, 5, 2);

            $results = mcRace(collect(range(0, 2))->map(fn ($i) => ['name' => "W{$i}", 'action' => $action, 'order' => $order])->all());

            expect(collect($results)->every(fn ($r) => $r['status'] === 'ok'))->toBeTrue("{$action} round {$round}: ".json_encode($results))
                ->and(collect($results)->pluck('value')->sort()->values()->all())->toBe([0, 0, 1])
                ->and(inventoryState($product))->toBe($expected);
        }
    }
});

// ---------------------------------------------------------------------
// 6. multi-line Orders: deterministic lock order (no deadlock)
// ---------------------------------------------------------------------

it('multi-line Orders whose lines are stored in OPPOSITE product order never deadlock, because locks are taken in ascending inventory id', function () {
    $store = Store::factory()->create();

    foreach (range(1, 25) as $round) {
        $p1 = inventoryProduct($store, '10.00', 'P1');
        $p2 = inventoryProduct($store, '10.00', 'P2');
        inventorySeed($p2, 10);
        inventorySeed($p1, 10);

        $orderX = inventoryOrder($store, [[$p1, 1], [$p2, 1]]);
        $orderY = inventoryOrder($store, [[$p1, 1], [$p2, 1]]);

        [$first, $second] = $orderY->items->sortBy('id')->values();
        DB::table('order_items')->where('id', $first->id)->update(['product_id' => $p2->id]);
        DB::table('order_items')->where('id', $second->id)->update(['product_id' => $p1->id]);

        $results = mcRace([
            ['name' => 'X', 'action' => 'reserve', 'order' => $orderX],
            ['name' => 'Y', 'action' => 'reserve', 'order' => $orderY],
        ]);

        expect(array_values(mcOutcomes($results)))->toBe(['ok', 'ok'], "round {$round}: ".json_encode($results))
            ->and(inventoryState($p1)['reserved'])->toBe(2)
            ->and(inventoryState($p2)['reserved'])->toBe(2);
    }
});

// ---------------------------------------------------------------------
// 7. complete rollback when one line has no stock
// ---------------------------------------------------------------------

it('an Order whose LAST line lacks stock leaves nothing behind, even while another buyer contends for its first line', function () {
    $store = Store::factory()->create();

    foreach (range(1, 12) as $round) {
        $p1 = inventoryProduct($store, '10.00', 'P1');
        $p2 = inventoryProduct($store, '10.00', 'P2');
        inventorySeed($p1, 1);
        inventorySeed($p2, 1);

        $failing = inventoryOrder($store, [[$p1, 1], [$p2, 5]]);
        $other = inventoryOrder($store, [[$p1, 1]]);

        $results = mcRace([
            ['name' => 'X', 'action' => 'reserve', 'order' => $failing],
            ['name' => 'Y', 'action' => 'reserve', 'order' => $other],
        ]);

        expect($results['X']['class'] ?? null)->toBe(InsufficientStockException::class, "round {$round}: ".json_encode($results))
            ->and($results['Y']['status'])->toBe('ok')
            ->and(mcStatuses($failing))->toBe([])
            ->and(mcStatuses($other))->toBe(['reserved'])
            ->and(inventoryState($p1))->toBe(['on_hand' => 1, 'reserved' => 1, 'available' => 0])
            ->and(inventoryState($p2))->toBe(['on_hand' => 1, 'reserved' => 0, 'available' => 1]);
    }
});

// ---------------------------------------------------------------------
// 8. multi-line commit/release: the same fixed lock order, on the settle paths
// ---------------------------------------------------------------------

it('multi-line commit() of one Order and release() of another, over shared inventories stored in opposite line order, never deadlock', function () {
    $store = Store::factory()->create();

    foreach (range(1, 12) as $round) {
        $p1 = inventoryProduct($store, '10.00', 'P1');
        $p2 = inventoryProduct($store, '10.00', 'P2');
        inventorySeed($p2, 10);
        inventorySeed($p1, 10);

        $orderX = inventoryOrder($store, [[$p1, 1], [$p2, 1]]);
        $orderY = inventoryOrder($store, [[$p1, 1], [$p2, 1]]);
        [$first, $second] = $orderY->items->sortBy('id')->values();
        DB::table('order_items')->where('id', $first->id)->update(['product_id' => $p2->id]);
        DB::table('order_items')->where('id', $second->id)->update(['product_id' => $p1->id]);

        mcService()->reserve($orderX);
        mcService()->reserve($orderY);

        $results = mcRace([
            ['name' => 'X', 'action' => 'commit', 'order' => $orderX],
            ['name' => 'Y', 'action' => 'release', 'order' => $orderY],
        ]);

        expect(array_values(mcOutcomes($results)))->toBe(['ok', 'ok'], "round {$round}: ".json_encode($results))
            ->and(mcStatuses($orderX))->toBe(['committed', 'committed'])
            ->and(mcStatuses($orderY))->toBe(['released', 'released'])
            ->and(inventoryState($p1))->toBe(['on_hand' => 9, 'reserved' => 0, 'available' => 9])
            ->and(inventoryState($p2))->toBe(['on_hand' => 9, 'reserved' => 0, 'available' => 9]);
    }
});

// ---------------------------------------------------------------------
// Last: every invariant, over everything the real concurrency above produced
// ---------------------------------------------------------------------

it('after all of the above, every inventory row satisfies the conservation equations (integrity audit of the real-concurrency data)', function () {
    $findings = (new InventoryIntegrityChecker)->audit();

    $violations = DB::selectOne(
        "select
            sum(case when i.reserved_quantity < 0 then 1 else 0 end) as negative_reserved,
            sum(case when i.on_hand_quantity < i.reserved_quantity then 1 else 0 end) as over_reserved,
            sum(case when i.reserved_quantity <> coalesce((select sum(r.quantity) from inventory_reservations r where r.inventory_id = i.id and r.status = 'reserved'), 0) then 1 else 0 end) as counter_drift
         from inventories i"
    );

    fwrite(STDERR, sprintf(
        "\n[audit] inventories=%d reservations=%d (reserved=%d committed=%d released=%d)\n",
        DB::table('inventories')->count(),
        DB::table('inventory_reservations')->count(),
        DB::table('inventory_reservations')->where('status', 'reserved')->count(),
        DB::table('inventory_reservations')->where('status', 'committed')->count(),
        DB::table('inventory_reservations')->where('status', 'released')->count(),
    ));

    expect($findings)->toBe([])
        ->and((int) $violations->negative_reserved)->toBe(0)
        ->and((int) $violations->over_reserved)->toBe(0)
        ->and((int) $violations->counter_drift)->toBe(0);
});
