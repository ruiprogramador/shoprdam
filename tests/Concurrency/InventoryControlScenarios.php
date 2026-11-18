<?php

use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InventoryIdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InventoryIntegrityException;
use App\Domain\Inventory\Services\InventoryControlService;
use App\Domain\Inventory\Services\InventoryCountService;
use App\Domain\Inventory\Services\InventoryIntegrityChecker;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REAL-ENGINE concurrency proof for Inventory Control
 * (docs/inventory/INVENTORY-CONTROL.md §10, §22.16). NOT a test file: each of
 * Inventory{Mysql,Mariadb,Postgres}ConcurrencyTest.php requires it after its own
 * safety guard and its own mcAwaitLockWait(), and Pest attributes these `it()`s to
 * that file, so that guard runs before every scenario here.
 *
 * Every actor is a separate OS process with its own connection (worker.php).
 * DETERMINISTIC scenarios hold a real open transaction and verify the other
 * process is genuinely blocked before letting go. RACE scenarios release workers
 * from a barrier over many rounds and assert invariants only. KILL scenarios
 * SIGKILL a process that holds an open transaction. T-17 scenarios let the engine
 * itself time a blocked process out, then repeat the call on the same connection.
 *
 * What these cannot show: freedom from deadlock under arbitrary load. The
 * interleavings are 2-4 workers; no soak or scale test.
 */

/**
 * This database keeps every round of every run, so Faker's random e-mail would
 * eventually collide on `users.email`. Owners get a guaranteed unique one.
 */
function icStore(): Store
{
    return Store::factory()->for(User::factory()->state(['email' => uniqid('owner', true).'@example.test']))->create();
}

/** @return array{store: Store, product: mixed, inventory: int, actor: int} */
function icFixture(int $stock, string $name = 'Widget', ?Store $store = null): array
{
    $store ??= icStore();
    [$product, $inventory] = inventoryGenesis($store, $stock, $name);

    return ['store' => $store, 'product' => $product, 'inventory' => $inventory, 'actor' => inventoryActor($product)->userId];
}

function icArgs(array $f, array $extra): array
{
    return ['product' => $f['product']->id, 'actor' => $f['actor'], 'note' => 'n', ...$extra];
}

function icReserved(array $f, int $quantity): Order
{
    $order = inventoryOrder($f['store'], [[$f['product'], $quantity]]);
    mcService()->reserve($order);

    return $order;
}

function icOnHand(array $f): int
{
    return (int) DB::table('inventories')->where('id', $f['inventory'])->value('on_hand_quantity');
}

function icHeld(array $f): int
{
    return (int) DB::table('inventories')->where('id', $f['inventory'])->value('reserved_quantity');
}

function icMovements(array $f, ?string $reason = null): int
{
    return DB::table('inventory_movements')->where('inventory_id', $f['inventory'])->when($reason, fn ($q) => $q->where('reason', $reason))->count();
}

/** The reconciliation equation, computed independently of the checker. */
function icAssertEquation(array $f): void
{
    $baseline = DB::table('inventory_baselines')->where('inventory_id', $f['inventory'])->first();
    $sum = (int) DB::table('inventory_movements')->where('inventory_id', $f['inventory'])->where('after_baseline', true)->sum('quantity_delta');

    expect($baseline)->not->toBeNull()
        ->and(icOnHand($f))->toBe((int) $baseline->on_hand_at_baseline + $sum)
        ->and(icHeld($f))->toBeLessThanOrEqual(icOnHand($f))
        ->and(icHeld($f))->toBeGreaterThanOrEqual(0)
        ->and(icOnHand($f))->toBeGreaterThanOrEqual(0);
}

/** @param  array<string, mixed>  $results */
function icNoDatabaseError(array $results): void
{
    foreach ($results as $name => $result) {
        expect($result['class'] ?? null)->not->toBe('Illuminate\Database\QueryException', "{$name}: ".($result['message'] ?? ''));
    }
}

/**
 * A holds its real operation in an open transaction, B is verified blocked behind
 * it, then A commits. Returns [A's result, B's result].
 *
 * @param  array{0: string, 1: mixed}  $first  action, and args or an Order
 * @param  array{0: string, 1: mixed}  $second
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function icHoldThenBlock(array $first, array $second): array
{
    $dir = mcDir();
    $spawn = fn (string $name, array $job, bool $hold) => $job[1] instanceof Order
        ? mcSpawn($dir, $name, $job[0], $job[1], hold: $hold)
        : mcSpawnOp($dir, $name, $job[0], $job[1], hold: $hold);

    $a = $spawn('A', $first, true);
    $b = $spawn('B', $second, false);
    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('B never blocked on a lock')
        ->and(file_exists("{$dir}/B.result"))->toBeFalse();

    // Not A's own finished_at: A stamps it after COMMIT returns, and the server may
    // release B first. What is provable is that B ended after A was told to commit.
    $releasedAt = microtime(true);
    mcSignal($dir, 'A.commit');

    $ra = mcResult($dir, 'A');
    $rb = mcResult($dir, 'B');
    mcJoin($a, $b);

    expect($ra['status'])->toBe('ok', (string) ($ra['message'] ?? ''))
        ->and($rb['finished_at'])->toBeGreaterThanOrEqual($releasedAt);

    return [$ra, $rb];
}

// ---------------------------------------------------------------------
// Receipts and write-offs against reservations
// ---------------------------------------------------------------------

it('receipt vs reserve: the reserve really waits behind the receipt, then sees the unit it brought', function () {
    $f = icFixture(0);
    $order = inventoryOrder($f['store'], [[$f['product'], 1]]);

    [, $rb] = icHoldThenBlock(['receive', icArgs($f, ['quantity' => 1, 'key' => 'r1'])], ['reserve', $order]);

    expect($rb['status'])->toBe('ok')
        ->and(icOnHand($f))->toBe(1)
        ->and(icHeld($f))->toBe(1)
        ->and(icMovements($f, 'receipt'))->toBe(1);

    icAssertEquation($f);
});

it('write-off vs reserve on the last available unit, both orders, deterministically: the loser is refused and nothing oversells', function () {
    $f = icFixture(1);
    $order = inventoryOrder($f['store'], [[$f['product'], 1]]);

    [, $rb] = icHoldThenBlock(['writeOff', icArgs($f, ['quantity' => 1, 'key' => 'w1'])], ['reserve', $order]);

    expect($rb['class'])->toBe(InsufficientStockException::class)
        ->and(icOnHand($f))->toBe(0)
        ->and(icHeld($f))->toBe(0);

    $g = icFixture(1);
    $order = inventoryOrder($g['store'], [[$g['product'], 1]]);

    [, $rb] = icHoldThenBlock(['reserve', $order], ['writeOff', icArgs($g, ['quantity' => 1, 'key' => 'w1'])]);

    expect($rb['class'])->toBe(InsufficientStockException::class)
        ->and(icOnHand($g))->toBe(1)
        ->and(icHeld($g))->toBe(1)
        ->and(icMovements($g, 'write_off'))->toBe(0);
});

it('write-off vs reserve on the last available unit, from a barrier over many rounds: exactly one wins, never both', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(1);
        $order = inventoryOrder($f['store'], [[$f['product'], 1]]);

        $results = mcRace([
            ['name' => 'w', 'action' => 'writeOff', 'args' => icArgs($f, ['quantity' => 1, 'key' => "w{$round}"])],
            ['name' => 'r', 'action' => 'reserve', 'order' => $order],
        ]);

        icNoDatabaseError($results);

        expect(collect(mcOutcomes($results))->filter(fn ($o) => $o === 'ok'))->toHaveCount(1, "round {$round}")
            ->and(icOnHand($f) - icHeld($f))->toBe(0)
            ->and(icMovements($f, 'write_off') + icHeld($f))->toBe(1);

        icAssertEquation($f);
    }
});

it('two write-offs of the last available unit from a barrier: exactly one wins', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(1);

        $results = mcRace([
            ['name' => 'a', 'action' => 'writeOff', 'args' => icArgs($f, ['quantity' => 1, 'key' => 'a'])],
            ['name' => 'b', 'action' => 'writeOff', 'args' => icArgs($f, ['quantity' => 1, 'key' => 'b'])],
            ['name' => 'c', 'action' => 'writeOff', 'args' => icArgs($f, ['quantity' => 1, 'key' => 'c'])],
        ]);

        icNoDatabaseError($results);

        expect(collect(mcOutcomes($results))->filter(fn ($o) => $o === 'ok'))->toHaveCount(1, "round {$round}")
            ->and(icOnHand($f))->toBe(0)
            ->and(icMovements($f, 'write_off'))->toBe(1);
    }
});

it('write-off vs commit: a write-off of what is available and a commit of what is reserved both land, with one movement each', function () {
    foreach (range(1, 6) as $round) {
        $f = icFixture(5);
        $order = icReserved($f, 3);

        $results = mcRace([
            ['name' => 'w', 'action' => 'writeOff', 'args' => icArgs($f, ['quantity' => 2, 'key' => 'w'])],
            ['name' => 'c', 'action' => 'commit', 'order' => $order],
        ]);

        expect(mcOutcomes($results))->toBe(['w' => 'ok', 'c' => 'ok'], "round {$round}")
            ->and(icOnHand($f))->toBe(0)
            ->and(icHeld($f))->toBe(0)
            ->and(icMovements($f, 'sale'))->toBe(1)
            ->and(icMovements($f, 'write_off'))->toBe(1);

        icAssertEquation($f);
    }
});

it('write-off never reaches reserved stock while a commit races it: the over-ask is refused', function () {
    foreach (range(1, 6) as $round) {
        $f = icFixture(5);
        $order = icReserved($f, 3);

        $results = mcRace([
            ['name' => 'w', 'action' => 'writeOff', 'args' => icArgs($f, ['quantity' => 3, 'key' => 'w'])],
            ['name' => 'c', 'action' => 'commit', 'order' => $order],
        ]);

        expect($results['w']['class'])->toBe(InsufficientStockException::class, "round {$round}")
            ->and($results['c']['status'])->toBe('ok')
            ->and(icOnHand($f))->toBe(2)
            ->and(icMovements($f, 'write_off'))->toBe(0);

        icAssertEquation($f);
    }
});

it('receipts with different keys from a barrier: every unit lands, with one movement each', function () {
    foreach (range(1, 6) as $round) {
        $f = icFixture(0);

        $results = mcRace(array_map(fn ($i) => [
            'name' => "r{$i}", 'action' => 'receive', 'args' => icArgs($f, ['quantity' => $i, 'key' => "k{$i}"]),
        ], [1, 2, 3, 4]));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}")
            ->and(icOnHand($f))->toBe(10)
            ->and(icMovements($f, 'receipt'))->toBe(4);

        icAssertEquation($f);
    }
});

it('receipt vs write-off from a barrier: both land whatever the order', function () {
    foreach (range(1, 6) as $round) {
        $f = icFixture(1);

        $results = mcRace([
            ['name' => 'r', 'action' => 'receive', 'args' => icArgs($f, ['quantity' => 1, 'key' => 'r'])],
            ['name' => 'w', 'action' => 'writeOff', 'args' => icArgs($f, ['quantity' => 1, 'key' => 'w'])],
        ]);

        expect(mcOutcomes($results))->toBe(['r' => 'ok', 'w' => 'ok'], "round {$round}")
            ->and(icOnHand($f))->toBe(1);

        icAssertEquation($f);
    }
});

// ---------------------------------------------------------------------
// Durable idempotency under real races
// ---------------------------------------------------------------------

it('duplicate receipt, deterministically: the second waits on the lock, loses the claim, rolls back and replays the winner', function () {
    $f = icFixture(0);
    $args = icArgs($f, ['quantity' => 5, 'key' => 'same']);

    [$ra, $rb] = icHoldThenBlock(['receive', $args], ['receive', $args]);

    expect($rb['status'])->toBe('ok', (string) ($rb['message'] ?? ''))
        ->and($rb['value']['replayed'])->toBeTrue()
        ->and($rb['value']['id'])->toBe($ra['value']['id'])
        ->and(icOnHand($f))->toBe(5)
        ->and(icMovements($f))->toBe(1);
});

it('same-key receipt from 3 workers over many rounds: one movement, one effect, the others replay it', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(0);
        $args = icArgs($f, ['quantity' => 5, 'key' => 'same']);

        $results = mcRace(array_map(fn ($i) => ['name' => "w{$i}", 'action' => 'receive', 'args' => $args], [1, 2, 3]));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and(collect($results)->pluck('value.replayed')->filter(fn ($r) => $r === false))->toHaveCount(1)
            ->and(collect($results)->pluck('value.id')->unique())->toHaveCount(1)
            ->and(icOnHand($f))->toBe(5)
            ->and(icMovements($f))->toBe(1);
    }
});

it('the same key with different payloads from a barrier: exactly one is applied, the other is refused, never both', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(0);

        $results = mcRace([
            ['name' => 'a', 'action' => 'receive', 'args' => icArgs($f, ['quantity' => 2, 'key' => 'same'])],
            ['name' => 'b', 'action' => 'receive', 'args' => icArgs($f, ['quantity' => 3, 'key' => 'same'])],
        ]);

        $outcomes = collect(mcOutcomes($results))->sort()->values()->all();

        expect($outcomes)->toBe([InventoryIdempotencyConflictException::class, 'ok'], "round {$round}: ".json_encode($results))
            ->and(icMovements($f))->toBe(1)
            ->and(icOnHand($f))->toBeIn([2, 3]);

        icAssertEquation($f);
    }
});

it('duplicate correction from 3 workers: one reversal', function () {
    foreach (range(1, 6) as $round) {
        $f = icFixture(10);
        $movement = app(InventoryControlService::class)->receive($f['product'], 4, 'mistake', inventoryActor($f['product']), 'n')->movement;
        $args = icArgs($f, ['movement' => $movement->id]);

        $results = mcRace(array_map(fn ($i) => ['name' => "w{$i}", 'action' => 'correct', 'args' => $args], [1, 2, 3]));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and(icOnHand($f))->toBe(10)
            ->and(DB::table('inventory_movements')->where('corrects_movement_id', $movement->id)->count())->toBe(1);

        icAssertEquation($f);
    }
});

it('correction vs reserve over the same units: a reversal never takes what was reserved', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(0);
        $movement = app(InventoryControlService::class)->receive($f['product'], 3, 'mistake', inventoryActor($f['product']), 'n')->movement;
        $order = inventoryOrder($f['store'], [[$f['product'], 3]]);

        $results = mcRace([
            ['name' => 'c', 'action' => 'correct', 'args' => icArgs($f, ['movement' => $movement->id])],
            ['name' => 'r', 'action' => 'reserve', 'order' => $order],
        ]);

        icNoDatabaseError($results);

        expect(collect(mcOutcomes($results))->filter(fn ($o) => $o === 'ok'))->toHaveCount(1, "round {$round}");

        icAssertEquation($f);
    }
});

it('create vs create from 3 workers: one Inventory, one genesis baseline, all converge on it', function () {
    foreach (range(1, 8) as $round) {
        $store = icStore();
        $product = inventoryProduct($store);
        $args = ['product' => $product->id, 'actor' => inventoryActor($product)->userId];

        $results = mcRace(array_map(fn ($i) => ['name' => "w{$i}", 'action' => 'create', 'args' => $args], [1, 2, 3]));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and(collect($results)->pluck('value')->unique())->toHaveCount(1)
            ->and(DB::table('inventories')->where('product_id', $product->id)->count())->toBe(1)
            ->and(DB::table('inventory_baselines')->where('inventory_id', $results['w1']['value'])->where('kind', 'genesis')->count())->toBe(1);
    }
});

// ---------------------------------------------------------------------
// Baselines: adopt vs commit, the stamp on the right side
// ---------------------------------------------------------------------

/** @return array{store: Store, product: mixed, inventory: int, actor: int} */
function icLegacy(int $stock): array
{
    $store = icStore();
    $product = inventoryProduct($store);
    $inventory = inventorySeed($product, $stock);

    return ['store' => $store, 'product' => $product, 'inventory' => $inventory, 'actor' => inventoryActor($product)->userId];
}

it('adopt first, commit blocked behind it: the sale is stamped after the baseline, read through the lock', function () {
    $f = icLegacy(10);
    $order = icReserved($f, 2);

    [, $rb] = icHoldThenBlock(['adopt', icArgs($f, [])], ['commit', $order]);

    $baseline = DB::table('inventory_baselines')->where('inventory_id', $f['inventory'])->sole();

    expect($rb['status'])->toBe('ok', (string) ($rb['message'] ?? ''))
        ->and((bool) DB::table('inventory_movements')->where('reason', 'sale')->where('inventory_id', $f['inventory'])->value('after_baseline'))->toBeTrue()
        ->and((int) $baseline->on_hand_at_baseline)->toBe(10)
        ->and((int) $baseline->reserved_at_baseline)->toBe(2)
        ->and(icOnHand($f))->toBe(8);

    icAssertEquation($f);
});

it('commit first, adopt blocked behind it: the sale is stamped before the baseline, and its effect is inside it', function () {
    $f = icLegacy(10);
    $order = icReserved($f, 2);

    [, $rb] = icHoldThenBlock(['commit', $order], ['adopt', icArgs($f, [])]);

    $baseline = DB::table('inventory_baselines')->where('inventory_id', $f['inventory'])->sole();

    expect($rb['status'])->toBe('ok', (string) ($rb['message'] ?? ''))
        ->and((bool) DB::table('inventory_movements')->where('reason', 'sale')->where('inventory_id', $f['inventory'])->value('after_baseline'))->toBeFalse()
        ->and((int) $baseline->on_hand_at_baseline)->toBe(8)
        ->and((int) $baseline->reserved_at_baseline)->toBe(0)
        ->and(icOnHand($f))->toBe(8);

    icAssertEquation($f);
});

it('adopt vs commit from a barrier over many rounds: the equation is exact whichever wins', function () {
    foreach (range(1, 10) as $round) {
        $f = icLegacy(10);
        $order = icReserved($f, 2);

        $results = mcRace([
            ['name' => 'a', 'action' => 'adopt', 'args' => icArgs($f, [])],
            ['name' => 'c', 'action' => 'commit', 'order' => $order],
        ]);

        expect(mcOutcomes($results))->toBe(['a' => 'ok', 'c' => 'ok'], "round {$round}: ".json_encode($results));

        icAssertEquation($f);
    }
});

it('adopt from 3 workers: one baseline, one flag flip, all converge', function () {
    foreach (range(1, 6) as $round) {
        $f = icLegacy(10);
        $args = icArgs($f, []);

        $results = mcRace(array_map(fn ($i) => ['name' => "w{$i}", 'action' => 'adopt', 'args' => $args], [1, 2, 3]));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and(collect($results)->pluck('value')->unique())->toHaveCount(1)
            ->and(DB::table('inventory_baselines')->where('inventory_id', $f['inventory'])->count())->toBe(1);
    }
});

it('adopt vs a control operation: a receipt blocked behind the adoption goes through once the flag is set', function () {
    $f = icLegacy(10);

    [, $rb] = icHoldThenBlock(['adopt', icArgs($f, [])], ['receive', icArgs($f, ['quantity' => 1, 'key' => 'r'])]);

    expect($rb['status'])->toBe('ok', (string) ($rb['message'] ?? ''))
        ->and(icOnHand($f))->toBe(11);

    icAssertEquation($f);
});

// ---------------------------------------------------------------------
// Physical counts
// ---------------------------------------------------------------------

it('count vs commit, the commit first: the count waits and comes out stale, undoing nothing', function () {
    $f = icFixture(10);
    $order = icReserved($f, 1);

    [, $rb] = icHoldThenBlock(['commit', $order], ['count', icArgs($f, ['expected' => 10, 'observed' => 9, 'key' => 'c'])]);

    expect($rb['value']['status'])->toBe('stale')
        ->and(icOnHand($f))->toBe(9)
        ->and(icMovements($f, 'count_adjustment'))->toBe(0);

    icAssertEquation($f);
});

it('count vs commit, the count first: the commit waits and still lands on the reduced stock', function () {
    $f = icFixture(10);
    $order = icReserved($f, 2);

    [$ra, $rb] = icHoldThenBlock(['count', icArgs($f, ['expected' => 10, 'observed' => 5, 'key' => 'c'])], ['commit', $order]);

    expect($ra['value']['status'])->toBe('applied')
        ->and($rb['status'])->toBe('ok', (string) ($rb['message'] ?? ''))
        ->and(icOnHand($f))->toBe(3)
        ->and(icHeld($f))->toBe(0);

    icAssertEquation($f);
});

it('count vs receipt, and count vs write-off: the operation first makes the count stale, never erased', function () {
    $f = icFixture(10);

    [, $rb] = icHoldThenBlock(['receive', icArgs($f, ['quantity' => 5, 'key' => 'r'])], ['count', icArgs($f, ['expected' => 10, 'observed' => 9, 'key' => 'c'])]);

    expect($rb['value']['status'])->toBe('stale')->and(icOnHand($f))->toBe(15);

    $g = icFixture(10);

    [, $rb] = icHoldThenBlock(['writeOff', icArgs($g, ['quantity' => 2, 'key' => 'w'])], ['count', icArgs($g, ['expected' => 10, 'observed' => 9, 'key' => 'c'])]);

    expect($rb['value']['status'])->toBe('stale')->and(icOnHand($g))->toBe(8);

    icAssertEquation($f);
    icAssertEquation($g);
});

it('count vs reserve, both orders: the outcome follows the serialization order and the CHECK is never touched', function () {
    $f = icFixture(10);
    $order = inventoryOrder($f['store'], [[$f['product'], 7]]);

    [, $rb] = icHoldThenBlock(['reserve', $order], ['count', icArgs($f, ['expected' => 10, 'observed' => 5, 'key' => 'c'])]);

    expect($rb['value']['status'])->toBe('discrepancy_open')
        ->and(icOnHand($f))->toBe(10)
        ->and(icHeld($f))->toBe(7);

    $g = icFixture(10);
    $order = inventoryOrder($g['store'], [[$g['product'], 7]]);

    [$ra, $rb] = icHoldThenBlock(['count', icArgs($g, ['expected' => 10, 'observed' => 5, 'key' => 'c'])], ['reserve', $order]);

    expect($ra['value']['status'])->toBe('applied')
        ->and($rb['class'])->toBe(InsufficientStockException::class)
        ->and(icOnHand($g))->toBe(5)
        ->and(icHeld($g))->toBe(0);
});

it('duplicate count, deterministically and from 3 workers: one row, one effect, one outcome for everyone', function () {
    $f = icFixture(10);
    $args = icArgs($f, ['expected' => 10, 'observed' => 8, 'key' => 'same']);

    [$ra, $rb] = icHoldThenBlock(['count', $args], ['count', $args]);

    expect($rb['status'])->toBe('ok', (string) ($rb['message'] ?? ''))
        ->and($rb['value']['replayed'])->toBeTrue()
        ->and($rb['value']['id'])->toBe($ra['value']['id'])
        ->and(icOnHand($f))->toBe(8);

    foreach (range(1, 6) as $round) {
        $g = icFixture(10);
        $args = icArgs($g, ['expected' => 10, 'observed' => 8, 'key' => 'same']);

        $results = mcRace(array_map(fn ($i) => ['name' => "w{$i}", 'action' => 'count', 'args' => $args], [1, 2, 3]));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and(collect($results)->pluck('value.id')->unique())->toHaveCount(1)
            ->and(icOnHand($g))->toBe(8)
            ->and(icMovements($g, 'count_adjustment'))->toBe(1);
    }
});

it('two counts of the same snapshot from a barrier: one applies, the other is stale, and the stock is what the winner saw', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(10);

        $results = mcRace([
            ['name' => 'a', 'action' => 'count', 'args' => icArgs($f, ['expected' => 10, 'observed' => 8, 'key' => 'a'])],
            ['name' => 'b', 'action' => 'count', 'args' => icArgs($f, ['expected' => 10, 'observed' => 7, 'key' => 'b'])],
        ]);

        $statuses = collect($results)->pluck('value.status')->sort()->values()->all();

        expect($statuses)->toBe(['applied', 'stale'], "round {$round}: ".json_encode($results))
            ->and(icOnHand($f))->toBeIn([7, 8])
            ->and(icMovements($f, 'count_adjustment'))->toBe(1);

        icAssertEquation($f);
    }
});

it('discrepancy resolution races: two applied counts resolve an open discrepancy exactly once', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(10);
        icReserved($f, 7);
        $actor = inventoryActor($f['product']);

        $open = app(InventoryCountService::class)->submitCount($f['product'], 10, 5, 'short', $actor)->count;

        $results = mcRace([
            ['name' => 'a', 'action' => 'count', 'args' => icArgs($f, ['expected' => 10, 'observed' => 10, 'key' => 'a'])],
            ['name' => 'b', 'action' => 'count', 'args' => icArgs($f, ['expected' => 10, 'observed' => 10, 'key' => 'b'])],
        ]);

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and(collect($results)->pluck('value.resolved')->flatten()->all())->toBe([$open->id])
            ->and($open->fresh()->status->value)->toBe('discrepancy_resolved')
            ->and(icMovements($f, 'count_adjustment'))->toBe(0);
    }
});

it('a discrepancy and a resolving count from a barrier: the row ends in a state its own facts prove', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(10);
        icReserved($f, 7);

        $results = mcRace([
            ['name' => 'short', 'action' => 'count', 'args' => icArgs($f, ['expected' => 10, 'observed' => 5, 'key' => 'short'])],
            ['name' => 'fine', 'action' => 'count', 'args' => icArgs($f, ['expected' => 10, 'observed' => 10, 'key' => 'fine'])],
        ]);

        $status = DB::table('inventory_counts')->where('idempotency_key', 'client:short')->where('inventory_id', $f['inventory'])->value('status');

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and($status)->toBeIn(['discrepancy_open', 'discrepancy_resolved'])
            ->and(icOnHand($f))->toBe(10);
    }
});

it('counts on neighbouring Inventories from a barrier: the discrepancy UPDATE never deadlocks them through its index gap', function () {
    $store = icStore();

    foreach (range(1, 8) as $round) {
        $fixtures = array_map(fn ($i) => icFixture(10, "N{$round}-{$i}", $store), [1, 2, 3, 4]);

        $results = mcRace(array_map(fn ($i, $f) => [
            'name' => "n{$i}", 'action' => 'count', 'args' => icArgs($f, ['expected' => 10, 'observed' => 9, 'key' => 'c']),
        ], array_keys($fixtures), $fixtures));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results));

        foreach ($fixtures as $f) {
            icAssertEquation($f);
        }
    }
});

// ---------------------------------------------------------------------
// Sale evidence, and multi-line operations against control operations
// ---------------------------------------------------------------------

it('duplicate commit from 3 workers over many rounds: one sale movement, one stock effect', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(5);
        $order = icReserved($f, 2);

        $results = mcRace(array_map(fn ($i) => ['name' => "w{$i}", 'action' => 'commit', 'order' => $order], [1, 2, 3]));

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results))
            ->and(collect($results)->pluck('value')->sum())->toBe(1)
            ->and(icMovements($f, 'sale'))->toBe(1)
            ->and(icOnHand($f))->toBe(3);

        icAssertEquation($f);
    }
});

it('commit vs release: only the winner of the reservation leaves stock evidence', function () {
    foreach (range(1, 8) as $round) {
        $f = icFixture(5);
        $order = icReserved($f, 2);

        $results = mcRace([
            ['name' => 'c', 'action' => 'commit', 'order' => $order],
            ['name' => 'r', 'action' => 'release', 'order' => $order],
        ]);

        icNoDatabaseError($results);

        $committed = $results['c']['status'] === 'ok';

        expect(icMovements($f, 'sale'))->toBe($committed ? 1 : 0, "round {$round}")
            ->and(icOnHand($f))->toBe($committed ? 3 : 5);

        icAssertEquation($f);
    }
});

it('multi-line commit and release in opposite line order, against control operations on the same Inventories: no deadlock', function () {
    foreach (range(1, 10) as $round) {
        $store = icStore();
        $a = icFixture(20, "A{$round}", $store);
        $b = icFixture(20, "B{$round}", $store);

        $first = inventoryOrder($store, [[$a['product'], 1], [$b['product'], 1]]);
        $second = inventoryOrder($store, [[$b['product'], 1], [$a['product'], 1]]);
        mcService()->reserve($first);
        mcService()->reserve($second);

        $results = mcRace([
            ['name' => 'commit', 'action' => 'commit', 'order' => $first],
            ['name' => 'release', 'action' => 'release', 'order' => $second],
            ['name' => 'writeOff', 'action' => 'writeOff', 'args' => icArgs($a, ['quantity' => 1, 'key' => 'w'])],
            ['name' => 'receive', 'action' => 'receive', 'args' => icArgs($b, ['quantity' => 1, 'key' => 'r'])],
        ]);

        expect(array_values(array_unique(mcOutcomes($results))))->toBe(['ok'], "round {$round}: ".json_encode($results));

        icAssertEquation($a);
        icAssertEquation($b);
    }
});

// ---------------------------------------------------------------------
// Real process deaths: SIGKILL with an open transaction
// ---------------------------------------------------------------------

/** Kills a worker holding its operation's open transaction, then proves the server rolled it back and released its locks. */
function icKillHolding(string $action, mixed $job, Closure $unchanged): void
{
    $dir = mcDir();
    $worker = $job instanceof Order ? mcSpawn($dir, 'victim', $action, $job, hold: true) : mcSpawnOp($dir, 'victim', $action, $job, hold: true);

    mcReady($dir, ['victim']);
    mcSignal($dir, 'victim.go');
    mcWait($dir, 'victim.locked');

    proc_terminate($worker['proc'], 9);
    proc_close($worker['proc']);

    $deadline = microtime(true) + 30;

    do {
        // Once the server notices the dead client, the row lock is free again.
        $free = true;

        try {
            DB::transaction(function () {
                DB::statement(DB::getDriverName() === 'pgsql' ? "SET LOCAL lock_timeout = '500ms'" : 'SET SESSION innodb_lock_wait_timeout = 1');
                DB::table('inventories')->lockForUpdate()->orderByDesc('id')->limit(1)->get();
            });
        } catch (Throwable) {
            $free = false;
            usleep(200_000);
        }
    } while (! $free && microtime(true) < $deadline);

    expect($free)->toBeTrue('the victim\'s locks were never released');

    $unchanged();
}

it('SIGKILL inside a receipt: claim and counter both vanish, and the retry applies once', function () {
    $f = icFixture(5);
    $args = icArgs($f, ['quantity' => 3, 'key' => 'killed']);

    icKillHolding('receive', $args, fn () => expect(icOnHand($f))->toBe(5)->and(icMovements($f, 'receipt'))->toBe(1));

    $result = app(InventoryControlService::class)->receive($f['product'], 3, 'killed', inventoryActor($f['product']), 'n');

    expect($result->replayed)->toBeFalse()->and(icOnHand($f))->toBe(8);
    icAssertEquation($f);
});

it('SIGKILL inside a write-off: nothing survives', function () {
    $f = icFixture(5);

    icKillHolding('writeOff', icArgs($f, ['quantity' => 3, 'key' => 'killed']), fn () => expect(icOnHand($f))->toBe(5)->and(icMovements($f, 'write_off'))->toBe(0));

    icAssertEquation($f);
});

it('SIGKILL inside a commit: the CAS, the counters and the sale all vanish, and the commit can still be won', function () {
    $f = icFixture(5);
    $order = icReserved($f, 2);

    icKillHolding('commit', $order, fn () => expect(icOnHand($f))->toBe(5)
        ->and(icHeld($f))->toBe(2)
        ->and(icMovements($f, 'sale'))->toBe(0)
        ->and(mcStatuses($order))->toBe(['reserved']));

    expect(mcService()->commit($order))->toBe(1)->and(icMovements($f, 'sale'))->toBe(1);
    icAssertEquation($f);
});

it('SIGKILL inside an applied count: the row, the adjustment and the resolution all vanish', function () {
    $f = icFixture(10);
    icReserved($f, 7);
    $open = app(InventoryCountService::class)->submitCount($f['product'], 10, 5, 'short', inventoryActor($f['product']))->count;

    icKillHolding('count', icArgs($f, ['expected' => 10, 'observed' => 9, 'key' => 'killed']), fn () => expect(icOnHand($f))->toBe(10)
        ->and(icMovements($f, 'count_adjustment'))->toBe(0)
        ->and(DB::table('inventory_counts')->where('inventory_id', $f['inventory'])->count())->toBe(1)
        ->and($open->fresh()->status->value)->toBe('discrepancy_open'));
});

it('SIGKILL inside an adoption: neither the baseline nor the flag survives', function () {
    $f = icLegacy(5);

    icKillHolding('adopt', icArgs($f, []), fn () => expect(DB::table('inventory_baselines')->where('inventory_id', $f['inventory'])->count())->toBe(0)
        ->and((bool) DB::table('inventories')->where('id', $f['inventory'])->value('is_baselined'))->toBeFalse());
});

// ---------------------------------------------------------------------
// T-17: a real lock-wait timeout, on the engine's own clock
// ---------------------------------------------------------------------

/** Seconds. Long enough for mcAwaitLockWait() to see B blocked, short enough to run. */
const IC_LOCK_TIMEOUT = 2;

/** Whether the engine itself still has a transaction open for this connection. */
function icOpenTransaction(int $connection): bool
{
    return DB::getDriverName() === 'pgsql'
        ? (int) DB::selectOne("select count(*) as n from pg_stat_activity where pid = ? and state like 'idle in transaction%'", [$connection])->n > 0
        : (int) DB::selectOne('select count(*) as n from information_schema.innodb_trx where trx_mysql_thread_id = ?', [$connection])->n > 0;
}

/** Whether this row can be locked right now: the timed-out worker kept nothing. */
function icLockable(int $inventoryId): bool
{
    try {
        DB::transaction(function () use ($inventoryId) {
            DB::statement(DB::getDriverName() === 'pgsql' ? "SET LOCAL lock_timeout = '500ms'" : 'SET SESSION innodb_lock_wait_timeout = 1');
            DB::table('inventories')->where('id', $inventoryId)->lockForUpdate()->get();
        });

        return true;
    } catch (Throwable) {
        return false;
    }
}

/** The engine's own timeout, exactly once, leaving neither a transaction nor a nesting level behind. */
function icAssertTimedOut(array $result, int $connection): void
{
    expect($result['status'])->toBe('error', json_encode($result));

    // A DeadlockException carries no errorInfo, only the driver's message.
    DB::getDriverName() === 'pgsql'
        ? expect($result['sqlstate'])->toBe('55P03', json_encode($result))
        : expect($result['message'])->toContain('1205 Lock wait timeout exceeded');

    $elapsed = $result['finished_at'] - $result['started_at'];

    // At least the whole timeout, and under two of them: a hidden retry would be 4s+.
    expect($elapsed)->toBeGreaterThanOrEqual(IC_LOCK_TIMEOUT - 0.2, "elapsed {$elapsed}")
        ->and($elapsed)->toBeLessThan((IC_LOCK_TIMEOUT * 2) - 0.05, "elapsed {$elapsed}: retried?")
        ->and($result['transaction_level'])->toBe(0)
        ->and(icOpenTransaction($connection))->toBeFalse('the timed-out worker still has an open transaction');
}

/**
 * A holds its real operation; B, on its own connection with a short timeout, runs
 * its real operation behind that lock until the ENGINE gives up. $whileHeld checks
 * the persisted state with A still holding; then A commits and B repeats the
 * identical call on the SAME connection. Returns [B's first outcome, its repeat].
 *
 * @param  array{0: string, 1: mixed}  $holder
 * @param  array{0: string, 1: mixed}  $victim
 * @param  array<string, mixed>  $job
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function icBehindLock(array $holder, array $victim, Closure $whileHeld, array $job = []): array
{
    $dir = mcDir();

    $a = $holder[1] instanceof Order
        ? mcSpawn($dir, 'A', $holder[0], $holder[1], hold: true)
        : mcSpawnOp($dir, 'A', $holder[0], $holder[1], hold: true);

    $b = mcSpawnJob($dir, 'B', [
        'action' => $victim[0],
        $victim[1] instanceof Order ? 'order' : 'args' => $victim[1] instanceof Order ? $victim[1]->id : $victim[1],
        'lock_timeout' => IC_LOCK_TIMEOUT,
        'retry' => true,
        ...$job,
    ]);

    $ids = mcReady($dir, ['A', 'B']);

    mcSignal($dir, 'A.go');
    mcWait($dir, 'A.locked');
    mcSignal($dir, 'B.go');

    expect(mcAwaitLockWait($ids['B']))->toBeTrue('B never blocked on a lock');

    $first = mcResult($dir, 'B');

    if (($job['compose'] ?? null) !== 'swallow') {
        icAssertTimedOut($first, $ids['B']);
    }

    $whileHeld($first);

    mcSignal($dir, 'A.commit');
    $ra = mcResult($dir, 'A');

    mcSignal($dir, 'B.retry');
    mcWait($dir, 'B.retry_result');
    $second = json_decode(file_get_contents("{$dir}/B.retry_result"), true, flags: JSON_THROW_ON_ERROR);

    mcJoin($a, $b);

    expect($ra['status'])->toBe('ok', (string) ($ra['message'] ?? ''));

    return [$first, $second];
}

/** Two Inventories, ascending, and one Order with a line on each: the first line is written before the second can block. */
function icTwoLines(int $stock): array
{
    $store = icStore();
    $a = icFixture($stock, 'First', $store);
    $b = icFixture($stock, 'Second', $store);

    expect($a['inventory'])->toBeLessThan($b['inventory']);

    return [$a, $b, inventoryOrder($store, [[$a['product'], 1], [$b['product'], 1]])];
}

it('T-17 receipt: times out behind the lock, leaves nothing, and the same key then applies exactly once', function () {
    $f = icFixture(5);
    $before = icMovements($f);

    [$timedOut, $retry] = icBehindLock(
        ['receive', icArgs($f, ['quantity' => 2, 'key' => 'holder'])],
        ['receive', icArgs($f, ['quantity' => 3, 'key' => 'victim'])],
        fn () => expect(icOnHand($f))->toBe(5)->and(icMovements($f))->toBe($before),
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value']['replayed'])->toBeFalse()
        ->and(icOnHand($f))->toBe(10)
        ->and(icMovements($f))->toBe($before + 2);

    $replay = app(InventoryControlService::class)->receive($f['product'], 3, 'victim', inventoryActor($f['product']), 'n');

    expect($replay->replayed)->toBeTrue()->and($replay->movement->id)->toBe($retry['value']['id'])->and(icOnHand($f))->toBe(10);
    icAssertEquation($f);

    fwrite(STDERR, "\n[T-17 ".DB::getDriverName()."] {$timedOut['class']} after ".round($timedOut['finished_at'] - $timedOut['started_at'], 2).'s: '.mb_substr($timedOut['message'], 0, 90)."\n");
});

it('T-17 write-off: times out behind the lock, leaves nothing, and the same key then applies exactly once', function () {
    $f = icFixture(5);
    $before = icMovements($f);

    [, $retry] = icBehindLock(
        ['receive', icArgs($f, ['quantity' => 2, 'key' => 'holder'])],
        ['writeOff', icArgs($f, ['quantity' => 3, 'key' => 'victim'])],
        fn () => expect(icOnHand($f))->toBe(5)->and(icMovements($f, 'write_off'))->toBe(0),
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and(icOnHand($f))->toBe(4)
        ->and(icMovements($f, 'write_off'))->toBe(1)
        ->and(icMovements($f))->toBe($before + 2);
    icAssertEquation($f);
});

it('T-17 unknown outcome: the same key behind its own uncommitted twin times out, and the repeat replays the winner', function () {
    $f = icFixture(5);
    $args = icArgs($f, ['quantity' => 3, 'key' => 'twin']);

    [, $retry] = icBehindLock(['receive', $args], ['receive', $args], fn () => expect(icOnHand($f))->toBe(5));

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value']['replayed'])->toBeTrue()
        ->and(icOnHand($f))->toBe(8)
        ->and(DB::table('inventory_movements')->where('inventory_id', $f['inventory'])->where('idempotency_key', 'client:twin')->count())->toBe(1);
    icAssertEquation($f);
});

it('T-17 multi-line reserve: the second line times out with the first already written, and all of it vanishes', function () {
    [$a, $b, $order] = icTwoLines(5);

    [, $retry] = icBehindLock(
        ['receive', icArgs($b, ['quantity' => 1, 'key' => 'holder'])],
        ['reserve', $order],
        fn () => expect(icHeld($a))->toBe(0)
            ->and(mcStatuses($order))->toBe([])
            ->and(icLockable($a['inventory']))->toBeTrue('the first line kept its lock'),
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and(icHeld($a))->toBe(1)
        ->and(icHeld($b))->toBe(1)
        ->and(mcStatuses($order))->toBe(['reserved', 'reserved']);
});

it('T-17 multi-line commit: the second line times out with the first CAS, counters and sale written, and all of it vanishes', function () {
    [$a, $b, $order] = icTwoLines(5);
    mcService()->reserve($order);

    [, $retry] = icBehindLock(
        ['receive', icArgs($b, ['quantity' => 1, 'key' => 'holder'])],
        ['commit', $order],
        fn () => expect(icOnHand($a))->toBe(5)
            ->and(icHeld($a))->toBe(1)
            ->and(icMovements($a, 'sale'))->toBe(0)
            ->and(mcStatuses($order))->toBe(['reserved', 'reserved'])
            ->and(icLockable($a['inventory']))->toBeTrue('the first line kept its lock'),
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value'])->toBe(2)
        ->and(mcService()->commit($order))->toBe(0)
        ->and(icOnHand($a))->toBe(4)
        ->and(icOnHand($b))->toBe(5)
        ->and(icMovements($a, 'sale'))->toBe(1)
        ->and(icMovements($b, 'sale'))->toBe(1);
    icAssertEquation($a);
    icAssertEquation($b);
});

it('T-17 multi-line release: the second line times out with the first written, and all of it vanishes', function () {
    [$a, $b, $order] = icTwoLines(5);
    mcService()->reserve($order);

    [, $retry] = icBehindLock(
        ['receive', icArgs($b, ['quantity' => 1, 'key' => 'holder'])],
        ['release', $order],
        fn () => expect(icHeld($a))->toBe(1)->and(mcStatuses($order))->toBe(['reserved', 'reserved']),
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value'])->toBe(2)
        ->and(icHeld($a))->toBe(0)
        ->and(icHeld($b))->toBe(0);
});

it('T-17 count: times out, stores nothing, and the repeat is stale against the stock that moved meanwhile', function () {
    $f = icFixture(10);

    [, $retry] = icBehindLock(
        ['receive', icArgs($f, ['quantity' => 2, 'key' => 'holder'])],
        ['count', icArgs($f, ['expected' => 10, 'observed' => 9, 'key' => 'victim'])],
        fn () => expect(DB::table('inventory_counts')->where('inventory_id', $f['inventory'])->count())->toBe(0),
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value']['status'])->toBe('stale')
        ->and(icOnHand($f))->toBe(12)
        ->and(icMovements($f, 'count_adjustment'))->toBe(0);
    icAssertEquation($f);
});

it('T-17 correction: times out, leaves nothing, and the repeat reverses once', function () {
    $f = icFixture(5);
    $receipt = app(InventoryControlService::class)->receive($f['product'], 2, 'wrong', inventoryActor($f['product']), 'n')->movement;

    [, $retry] = icBehindLock(
        ['receive', icArgs($f, ['quantity' => 1, 'key' => 'holder'])],
        ['correct', icArgs($f, ['movement' => $receipt->id])],
        fn () => expect(icMovements($f, 'correction'))->toBe(0),
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value']['replayed'])->toBeFalse()
        ->and(icOnHand($f))->toBe(6)
        ->and(icMovements($f, 'correction'))->toBe(1);
    icAssertEquation($f);
});

it('T-17 adoption: times out, neither baseline nor flag exists, and the repeat adopts the row as it then stands', function () {
    $f = icLegacy(5);
    $order = inventoryOrder($f['store'], [[$f['product'], 1]]);

    [, $retry] = icBehindLock(
        ['reserve', $order],
        ['adopt', icArgs($f, [])],
        fn () => expect(DB::table('inventory_baselines')->where('inventory_id', $f['inventory'])->count())->toBe(0)
            ->and((bool) DB::table('inventories')->where('id', $f['inventory'])->value('is_baselined'))->toBeFalse(),
    );

    $baseline = DB::table('inventory_baselines')->where('inventory_id', $f['inventory'])->first();

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and((int) $baseline->on_hand_at_baseline)->toBe(5)
        ->and((int) $baseline->reserved_at_baseline)->toBe(1);
    icAssertEquation($f);
});

it('T-17 creation: the identical unique claim times out behind its uncommitted twin, and the repeat replays the one Inventory', function () {
    $store = icStore();
    $product = inventoryProduct($store);
    $args = ['product' => $product->id, 'actor' => inventoryActor($product)->userId];

    [, $retry] = icBehindLock(['create', $args], ['create', $args], fn () => expect(DB::table('inventories')->where('product_id', $product->id)->count())->toBe(0));

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and(DB::table('inventories')->where('product_id', $product->id)->count())->toBe(1)
        ->and(DB::table('inventory_baselines')->where('inventory_id', $retry['value'])->count())->toBe(1);
});

it('T-17 composed in a caller transaction that lets the error escape: nothing of the multi-line commit survives', function () {
    [$a, $b, $order] = icTwoLines(5);
    mcService()->reserve($order);

    [, $retry] = icBehindLock(
        ['receive', icArgs($b, ['quantity' => 1, 'key' => 'holder'])],
        ['commit', $order],
        fn () => expect(icOnHand($a))->toBe(5)->and(icMovements($a, 'sale'))->toBe(0)->and(mcStatuses($order))->toBe(['reserved', 'reserved']),
        ['compose' => 'rethrow'],
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value'])->toBe(2)
        ->and(icMovements($a, 'sale'))->toBe(1)
        ->and(icMovements($b, 'sale'))->toBe(1);
    icAssertEquation($a);
    icAssertEquation($b);
});

// ---------------------------------------------------------------------
// KNOWN DANGER, not safe behavior. A caller that swallows the timeout and commits
// ---------------------------------------------------------------------

/*
 * docs/inventory/INVENTORY-CONTROL.md §24.7. These scenarios pin a DEFECT OF
 * COMPOSITION so that it stays visible; they do not bless it. On MySQL and MariaDB
 * a nested timeout reaches the caller as a DeadlockException with no savepoint
 * rollback, and the engine undid only the timed-out statement, so an outer commit
 * persists the lines already written. Nothing prevents that: the rule forbidding
 * this caller shape is documentation, not a technical guarantee, and production
 * integration stays blocked (the empty allowlist in InventoryBoundaryTest) until a
 * verifiable composition strategy exists. A red here after a framework or service
 * change means the danger moved: re-read §24.7 before changing the expectations.
 */

/** Whether this engine leaves the first line behind when the caller swallows the error. */
function icKeepsPartialWrites(): bool
{
    return DB::getDriverName() !== 'pgsql';
}

/** @return string the exception class the swallowing caller saw */
function icSwallowedClass(array $first): string
{
    expect($first['status'])->toBe('ok', 'the outer transaction was meant to commit')
        ->and($first['swallowed'])->not->toBeNull('the caller was meant to catch something');

    return $first['swallowed'];
}

it('T-17 KNOWN DANGER, NOT SAFE: a swallowed timeout commits half an Order on MySQL and MariaDB, sale included', function () {
    [$a, $b, $order] = icTwoLines(5);
    mcService()->reserve($order);

    [, $retry] = icBehindLock(
        ['receive', icArgs($b, ['quantity' => 1, 'key' => 'holder'])],
        ['commit', $order],
        function ($first) use ($a, $b, $order) {
            $seen = icSwallowedClass($first);

            icKeepsPartialWrites()
                ? expect($seen)->toBe(DeadlockException::class)
                    ->and(mcStatuses($order))->toBe(['committed', 'reserved'])
                    ->and(icOnHand($a))->toBe(4)
                    ->and(icHeld($a))->toBe(0)
                    ->and(icMovements($a, 'sale'))->toBe(1)
                    ->and(icMovements($b, 'sale'))->toBe(0)
                : expect($seen)->toBe(QueryException::class)
                    ->and(mcStatuses($order))->toBe(['reserved', 'reserved'])
                    ->and(icOnHand($a))->toBe(5)
                    ->and(icMovements($a, 'sale'))->toBe(0);

            // The damage is invisible to the checker: each line is consistent on its own.
            expect(app(InventoryIntegrityChecker::class)->audit())->toBe([]);
        },
        ['compose' => 'swallow'],
    );

    // Repeating the commit finishes the Order, with one sale per line. It repairs the
    // data only if somebody repeats it; until then the Order is half committed.
    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value'])->toBe(icKeepsPartialWrites() ? 1 : 2)
        ->and(icMovements($a, 'sale'))->toBe(1)
        ->and(icMovements($b, 'sale'))->toBe(1);
    icAssertEquation($a);
    icAssertEquation($b);
});

it('T-17 KNOWN DANGER, NOT SAFE: a swallowed timeout half-reserves an Order on MySQL and MariaDB, and the repeat then fails closed', function () {
    [$a, $b, $order] = icTwoLines(5);

    [, $retry] = icBehindLock(
        ['receive', icArgs($b, ['quantity' => 1, 'key' => 'holder'])],
        ['reserve', $order],
        function ($first) use ($a, $order) {
            icSwallowedClass($first);

            icKeepsPartialWrites()
                ? expect(mcStatuses($order))->toBe(['reserved'])->and(icHeld($a))->toBe(1)
                : expect(mcStatuses($order))->toBe([])->and(icHeld($a))->toBe(0);
        },
        ['compose' => 'swallow'],
    );

    // Worse than the commit: nothing here completes the Order. reserve() refuses it
    // as partially reserved, and the one unit stays held on the first line.
    icKeepsPartialWrites()
        ? expect($retry['swallowed'])->toBe(InventoryIntegrityException::class, json_encode($retry))
            ->and(icHeld($a))->toBe(1)
            ->and(icHeld($b))->toBe(0)
        : expect($retry['swallowed'])->toBeNull()
            ->and(icHeld($a))->toBe(1)
            ->and(icHeld($b))->toBe(1);
});

it('T-17 KNOWN DANGER, NOT SAFE: a swallowed timeout half-releases an Order on MySQL and MariaDB', function () {
    [$a, $b, $order] = icTwoLines(5);
    mcService()->reserve($order);

    [, $retry] = icBehindLock(
        ['receive', icArgs($b, ['quantity' => 1, 'key' => 'holder'])],
        ['release', $order],
        function ($first) use ($a, $order) {
            icSwallowedClass($first);

            icKeepsPartialWrites()
                ? expect(mcStatuses($order))->toBe(['released', 'reserved'])->and(icHeld($a))->toBe(0)
                : expect(mcStatuses($order))->toBe(['reserved', 'reserved'])->and(icHeld($a))->toBe(1);
        },
        ['compose' => 'swallow'],
    );

    expect($retry['status'])->toBe('ok', (string) ($retry['message'] ?? ''))
        ->and($retry['value'])->toBe(icKeepsPartialWrites() ? 1 : 2)
        ->and(icHeld($a))->toBe(0)
        ->and(icHeld($b))->toBe(0);
});

// ---------------------------------------------------------------------
// Last: every equation, over everything real concurrency produced
// ---------------------------------------------------------------------

it('after all of the above, the integrity audit finds nothing and every baselined Inventory reconciles', function () {
    $findings = (new InventoryIntegrityChecker)->audit();

    $drift = DB::selectOne(
        'select count(*) as n from inventories i join inventory_baselines b on b.inventory_id = i.id
          where i.on_hand_quantity <> b.on_hand_at_baseline + coalesce((select sum(m.quantity_delta) from inventory_movements m where m.inventory_id = i.id and m.after_baseline = '.(DB::getDriverName() === 'pgsql' ? 'true' : '1').'), 0)'
    );

    $invalid = DB::selectOne('select count(*) as n from inventories where reserved_quantity > on_hand_quantity or on_hand_quantity < 0 or reserved_quantity < 0');

    fwrite(STDERR, sprintf(
        "\n[control audit] inventories=%d baselined=%d movements=%d (sale=%d) counts=%d (applied=%d stale=%d open=%d resolved=%d) baselines=%d\n",
        DB::table('inventories')->count(),
        DB::table('inventories')->where('is_baselined', true)->count(),
        DB::table('inventory_movements')->count(),
        DB::table('inventory_movements')->where('reason', 'sale')->count(),
        DB::table('inventory_counts')->count(),
        DB::table('inventory_counts')->where('status', 'applied')->count(),
        DB::table('inventory_counts')->where('status', 'stale')->count(),
        DB::table('inventory_counts')->where('status', 'discrepancy_open')->count(),
        DB::table('inventory_counts')->where('status', 'discrepancy_resolved')->count(),
        DB::table('inventory_baselines')->count(),
    ));

    expect($findings)->toBe([])
        ->and((int) $drift->n)->toBe(0)
        ->and((int) $invalid->n)->toBe(0);
});
