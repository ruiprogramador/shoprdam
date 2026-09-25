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
 * MariaDB sibling of InventoryMysqlConcurrencyTest.php (see that file's
 * header for the full scenario catalogue and protocol description; the two
 * are line-for-line equivalent test-by-test, sharing worker.php and
 * ConcurrencyHelpers.php verbatim). Proven independently here, not inferred
 * from the MySQL run: MariaDB forked from MySQL long ago and its InnoDB lock
 * instrumentation has since diverged (see mcAwaitLockWait below).
 *
 * Two real, engine-specific differences from MySQL this file had to account
 * for rather than assume away:
 *
 * - MariaDB has no `performance_schema.data_lock_waits` (a MySQL 8.0+
 *   addition). Lock-wait detection here instead uses MariaDB's own
 *   `information_schema.INNODB_LOCK_WAITS` joined to `INNODB_TRX` — verified
 *   empirically against a real blocked UPDATE before being used in this
 *   harness (see this branch's Round 5 report §InventoryConcurrency).
 * - The CHECK-constraint violation driver error code is 4025 on MariaDB,
 *   not MySQL's 3819 (also confirmed empirically this round, independently
 *   of this harness, when the identical constraint was hand-tested against
 *   both servers).
 *
 * Run:
 *
 *   INVENTORY_MARIADB_CONCURRENCY=1 DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=… \
 *   DB_DATABASE=<name>_concurrency_test DB_USERNAME=… DB_PASSWORD=… \
 *   [INVENTORY_CONCURRENCY_WORKER_PHP_ARGS="-d extension=pdo_mysql"] \
 *   php vendor/pestphp/pest/bin/pest tests/Concurrency/InventoryMariadbConcurrencyTest.php
 *
 * against a database that has been `migrate`d. It refuses any database whose
 * name does not end in `_concurrency_test` or whose host is not local.
 */
require __DIR__.'/ConcurrencyHelpers.php';

beforeEach(function () {
    if (getenv('INVENTORY_MARIADB_CONCURRENCY') !== '1') {
        $this->markTestSkipped('Set INVENTORY_MARIADB_CONCURRENCY=1 and point DB_* at a disposable local MariaDB *_concurrency_test database.');
    }

    $connection = config('database.default');
    $settings = config("database.connections.{$connection}");

    if ($connection !== 'mariadb' || ! in_array($settings['host'], ['127.0.0.1', 'localhost'], true) || ! str_ends_with((string) $settings['database'], '_concurrency_test')) {
        throw new RuntimeException('Refusing to run: the effective connection is not a disposable local MariaDB *_concurrency_test database.');
    }
});

/**
 * Whether the given MariaDB connection is currently waiting on an InnoDB
 * lock held by another transaction.
 *
 * NOT backed by `information_schema.innodb_lock_waits`/`innodb_trx` (unlike
 * this file's earlier version, and unlike MySQL's equivalent in
 * InventoryMysqlConcurrencyTest.php). Confirmed empirically on real MariaDB
 * 11.8.9, reproducibly, three independent ways (two hand-run SQL sessions
 * with microsecond timestamps, and this suite's own harness): a connection
 * genuinely blocked on a row lock for its *entire* duration — proven by a
 * second connection resolving within ~1ms of the holder's COMMIT after
 * being blocked — appears in `innodb_lock_waits`/`innodb_trx.trx_state =
 * 'LOCK WAIT'` for only the first 1-2 seconds of the wait, then silently
 * drops out of both views while the wait (and the underlying InnoDB lock)
 * continues unchanged. Polling either table missed the block entirely in
 * this suite's original run, timing out at 30s even though the same
 * connection was later shown (via `information_schema.processlist`, and via
 * hitting the real `innodb_lock_wait_timeout` at exactly 50s) to have been
 * blocked the whole time. This looks like a monitoring-table gap specific
 * to MariaDB's legacy INNODB_LOCK_WAITS/INNODB_TRX implementation, not a
 * locking behavior difference — MariaDB's actual row-locking here is
 * identical to MySQL's.
 *
 * `information_schema.processlist` (this connection's `command <> 'Sleep'`)
 * does NOT have that gap: verified to read "busy" on 267/274 polls (97%)
 * across a continuous 6-second real block. A single busy reading isn't
 * enough on its own — an uncontended query passes through this same state
 * too, just for microseconds — so REQUIRED_CONSECUTIVE_BUSY_POLLS demands
 * several in a row before concluding "blocked", which a fast, uncontended
 * query cannot produce.
 */
function mcAwaitLockWait(int $connectionId, float $seconds = 30.0): bool
{
    $requiredConsecutiveBusyPolls = 3;

    $deadline = microtime(true) + $seconds;
    $consecutiveBusy = 0;

    do {
        $busy = DB::selectOne(
            "select count(*) as n from information_schema.processlist where id = ? and command <> 'Sleep'",
            [$connectionId],
        )->n;

        $consecutiveBusy = $busy > 0 ? $consecutiveBusy + 1 : 0;

        if ($consecutiveBusy >= $requiredConsecutiveBusyPolls) {
            return true;
        }

        usleep(20_000);
    } while (microtime(true) < $deadline);

    return false;
}

// ---------------------------------------------------------------------
// The real engine, and the schema on it
// ---------------------------------------------------------------------

it('runs on real MariaDB/InnoDB at the production default isolation level', function () {
    $server = DB::selectOne('select version() as v, @@transaction_isolation as i, @@innodb_lock_wait_timeout as t, @@default_storage_engine as e');

    expect($server->i)->toBe('REPEATABLE-READ')
        ->and($server->e)->toBe('InnoDB')
        ->and(DB::getDriverName())->toBe('mariadb');

    fwrite(STDERR, "\n[mariadb] {$server->v} isolation={$server->i} lock_wait_timeout={$server->t}s\n");
});

it('enforces the CHECK constraints and RESTRICT foreign keys on MariaDB itself, not only the SQLite triggers', function () {
    $checks = array_column(DB::select(
        'select constraint_name as name from information_schema.check_constraints where constraint_schema = ?',
        [DB::getDatabaseName()],
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

    $codes = [];

    try {
        foreach ($refused as $label => $attempt) {
            expect($attempt)->toThrow(QueryException::class);
        }

        try {
            DB::table('inventories')->where('id', $inventoryId)->update(['reserved_quantity' => 6]);
        } catch (QueryException $e) {
            $codes[] = $e->errorInfo[1];
        }
    } finally {
        DB::table('inventory_reservations')->where('inventory_id', $inventoryId)->delete();
        DB::table('inventories')->where('id', $inventoryId)->delete();
    }

    expect($codes)->toBe([4025]); // ER_CONSTRAINT_FAILED on MariaDB (MySQL uses 3819) — the constraint is really enforced
});

// ---------------------------------------------------------------------
// 1. stock = 1, two buyers, DIFFERENT Orders
// ---------------------------------------------------------------------

it('stock=1, two different Orders: B is genuinely blocked behind A\'s InnoDB row lock, then refused — exactly one reservation', function () {
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
            sum(i.reserved_quantity < 0) as negative_reserved,
            sum(i.on_hand_quantity < i.reserved_quantity) as over_reserved,
            sum(i.reserved_quantity <> coalesce((select sum(r.quantity) from inventory_reservations r where r.inventory_id = i.id and r.status = 'reserved'), 0)) as counter_drift
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
