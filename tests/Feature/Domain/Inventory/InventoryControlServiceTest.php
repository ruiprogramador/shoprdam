<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\DTOs\StoreOwnerActor;
use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryBaselineKind;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidInventoryOperationException;
use App\Domain\Inventory\Exceptions\InventoryIdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InventoryIntegrityException;
use App\Domain\Inventory\Exceptions\InventoryNotBaselinedException;
use App\Domain\Inventory\Exceptions\InventoryQuantityCeilingException;
use App\Domain\Inventory\Models\InventoryBaseline;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Services\InventoryControlService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The control operations of docs/inventory/INVENTORY-CONTROL.md §6, on the
 * default SQLite suite: algorithm, state machine and SQL shape only. SQLite proves
 * nothing about locks or races; tests/Concurrency does that on real engines.
 * Every "simulation" below says so.
 */
function ctl(): InventoryControlService
{
    return app(InventoryControlService::class);
}

function ctlMovements(int $inventoryId): int
{
    return DB::table('inventory_movements')->where('inventory_id', $inventoryId)->count();
}

function ctlSeededInventoryState(int $inventoryId): array
{
    $row = DB::table('inventories')->find($inventoryId);

    return ['on_hand' => (int) $row->on_hand_quantity, 'reserved' => (int) $row->reserved_quantity];
}

// ---------------------------------------------------------------------
// createInventory
// ---------------------------------------------------------------------

it('creates an Inventory at 0/0 with its genesis baseline, atomically, and no movement', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);

    $inventory = ctl()->createInventory($product, $actor);

    $baseline = InventoryBaseline::query()->where('inventory_id', $inventory->id)->sole();

    expect($inventory->wasRecentlyCreated)->toBeTrue()
        ->and($inventory->on_hand_quantity)->toBe(0)
        ->and($inventory->reserved_quantity)->toBe(0)
        ->and($inventory->is_baselined)->toBeTrue()
        ->and($baseline->kind)->toBe(InventoryBaselineKind::Genesis)
        ->and($baseline->on_hand_at_baseline)->toBe(0)
        ->and($baseline->reserved_at_baseline)->toBe(0)
        ->and($baseline->actor_type)->toBe(InventoryActorType::StoreOwner)
        ->and($baseline->actor_user_id)->toBe($actor->userId)
        ->and(ctlMovements($inventory->id))->toBe(0);
});

it('replays a repeated creation with no second row and no second baseline', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);

    $first = ctl()->createInventory($product, $actor);
    $second = ctl()->createInventory($product->id, $actor);

    expect($second->id)->toBe($first->id)
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and(DB::table('inventories')->where('product_id', $product->id)->count())->toBe(1)
        ->and(DB::table('inventory_baselines')->where('inventory_id', $first->id)->count())->toBe(1);
});

it('refuses to create over a legacy Inventory, and over an adopted one: silently returning either would hide its provenance', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    inventorySeed($product, 5);

    expect(fn () => ctl()->createInventory($product, $actor))->toThrow(InvalidInventoryOperationException::class, 'must be adopted');

    ctl()->adopt($product, $actor, 'stock found on shelf');

    expect(fn () => ctl()->createInventory($product, $actor))->toThrow(InvalidInventoryOperationException::class, 'did not come from this operation');
});

it('refuses to create for a missing or soft-deleted Product, and for anyone but the Store owner', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $stranger = new StoreOwnerActor(User::factory()->create()->id);

    expect(fn () => ctl()->createInventory($product, $stranger))->toThrow(InvalidInventoryOperationException::class, 'does not own')
        ->and(fn () => ctl()->createInventory(999999, $actor))->toThrow(InvalidInventoryOperationException::class, 'does not exist');

    $product->delete();

    expect(fn () => ctl()->createInventory($product->id, $actor))->toThrow(InvalidInventoryOperationException::class, 'is deleted')
        ->and(DB::table('inventories')->count())->toBe(0)
        ->and(DB::table('inventory_baselines')->count())->toBe(0);
});

it('stocks an inactive Product: is_active is catalog visibility, never inventory', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    Product::query()->whereKey($product->id)->update(['is_active' => false]);

    $inventory = ctl()->createInventory($product->id, inventoryActor($product));

    expect($inventory->is_baselined)->toBeTrue();
});

it('recovers a creation race: the loser rolls back, retries and replays the winner (simulation: a competitor commits between pre-check and insert)', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);

    $armed = true;

    DB::listen(function (QueryExecuted $q) use (&$armed, $product) {
        if ($armed && str_starts_with($q->sql, 'select') && preg_match('/from "inventories" where "product_id"/', inventorySql($q->sql))) {
            $armed = false;
            $id = DB::table('inventories')->insertGetId(['product_id' => $product->id, 'on_hand_quantity' => 0, 'reserved_quantity' => 0, 'is_baselined' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('inventory_baselines')->insert(['inventory_id' => $id, 'kind' => 'genesis', 'on_hand_at_baseline' => 0, 'reserved_at_baseline' => 0, 'actor_type' => 'store_owner', 'actor_user_id' => 1, 'created_at' => now()]);
        }
    });

    try {
        $inventory = ctl()->createInventory($product, $actor);
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($inventory->wasRecentlyCreated)->toBeFalse()
        ->and(DB::table('inventories')->where('product_id', $product->id)->count())->toBe(1)
        ->and(DB::table('inventory_baselines')->count())->toBe(1);
});

// ---------------------------------------------------------------------
// adopt
// ---------------------------------------------------------------------

it('adopts a legacy Inventory as it stands: no quantity changes, no movement is fabricated, the flag flips once', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $id = inventorySeed($product, 10, 3);

    $baseline = ctl()->adopt($product, $actor, 'adopted after review');

    expect($baseline->kind)->toBe(InventoryBaselineKind::Adopted)
        ->and($baseline->on_hand_at_baseline)->toBe(10)
        ->and($baseline->reserved_at_baseline)->toBe(3)
        ->and($baseline->actor_user_id)->toBe($actor->userId)
        ->and($baseline->note)->toBe('adopted after review')
        ->and(ctlSeededInventoryState($id))->toBe(['on_hand' => 10, 'reserved' => 3])
        ->and((bool) DB::table('inventories')->where('id', $id)->value('is_baselined'))->toBeTrue()
        ->and(ctlMovements($id))->toBe(0);
});

it('replays an identical adoption and refuses a different one, with zero second effect', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $id = inventorySeed($product, 10);

    $first = ctl()->adopt($product, $actor, 'note');
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 9]);

    $second = ctl()->adopt($product, $actor, 'note');

    expect($second->id)->toBe($first->id)
        ->and($second->on_hand_at_baseline)->toBe(10)
        ->and(fn () => ctl()->adopt($product, $actor, 'another note'))->toThrow(InventoryIdempotencyConflictException::class)
        ->and(DB::table('inventory_baselines')->where('inventory_id', $id)->count())->toBe(1);
});

it('refuses to adopt a genesis Inventory: it is not legacy', function () {
    [$product] = inventoryGenesis(Store::factory()->create());

    expect(fn () => ctl()->adopt($product, inventoryActor($product), 'note'))->toThrow(InvalidInventoryOperationException::class);
});

it('fails closed on a baseline row whose flag was raw-reset, instead of replaying over the corruption', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $id = inventorySeed($product, 10);

    ctl()->adopt($product, $actor, 'note');
    DB::table('inventories')->where('id', $id)->update(['is_baselined' => false]);

    expect(fn () => ctl()->adopt($product, $actor, 'note'))->toThrow(InventoryIntegrityException::class);
});

it('fails closed on a flag raw-set without a baseline row', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $id = inventorySeed($product, 10);
    DB::table('inventories')->where('id', $id)->update(['is_baselined' => true]);

    expect(fn () => ctl()->adopt($product, inventoryActor($product), 'note'))->toThrow(InventoryIntegrityException::class)
        ->and(DB::table('inventory_baselines')->count())->toBe(0);
});

it('refuses when the flag compare-and-set finds nothing to flip under its own lock, and rolls the baseline back (simulation)', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $id = inventorySeed($product, 10);

    DB::listen(function (QueryExecuted $q) use ($id) {
        if (str_starts_with(inventorySql($q->sql), 'insert into "inventory_baselines"')) {
            Event::forget(QueryExecuted::class);
            DB::table('inventories')->where('id', $id)->update(['is_baselined' => true]);
        }
    });

    try {
        expect(fn () => ctl()->adopt($product, inventoryActor($product), 'note'))->toThrow(InventoryIntegrityException::class, 'under its own lock');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect(DB::table('inventory_baselines')->count())->toBe(0);
});

it('runs inside a caller-owned transaction: adoption depends on no read view', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $id = inventorySeed($product, 4);

    $baseline = DB::transaction(fn () => ctl()->adopt($product, inventoryActor($product), 'note'));

    expect($baseline->on_hand_at_baseline)->toBe(4)
        ->and((bool) DB::table('inventories')->where('id', $id)->value('is_baselined'))->toBeTrue();
});

it('adopts in one transaction: a failure after the baseline insert leaves neither the baseline nor the flag', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $id = inventorySeed($product, 4);

    DB::listen(function (QueryExecuted $q) {
        if (preg_match('/^update "inventories"/', inventorySql($q->sql))) {
            throw new RuntimeException('simulated crash');
        }
    });

    try {
        expect(fn () => ctl()->adopt($product, inventoryActor($product), 'note'))->toThrow(RuntimeException::class, 'simulated crash');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect(DB::table('inventory_baselines')->count())->toBe(0)
        ->and((bool) DB::table('inventories')->where('id', $id)->value('is_baselined'))->toBeFalse();
});

// ---------------------------------------------------------------------
// the baseline precondition, and the actor
// ---------------------------------------------------------------------

it('refuses every control operation on a legacy Inventory, leaving no row, and accepts it once adopted', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $id = inventorySeed($product, 5);

    expect(fn () => ctl()->receive($product, 1, 'k1', $actor, 'n'))->toThrow(InventoryNotBaselinedException::class)
        ->and(fn () => ctl()->writeOff($product, 1, 'k2', $actor, 'n'))->toThrow(InventoryNotBaselinedException::class)
        ->and(ctlMovements($id))->toBe(0)
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(5);

    ctl()->adopt($product, $actor, 'note');

    // A refusal is not memoized: the same key re-evaluates and now succeeds.
    expect(ctl()->receive($product, 1, 'k1', $actor, 'n')->replayed)->toBeFalse()
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(6);
});

it('takes the baseline decision from the locked row: the flag read under the lock wins over the pre-transaction state (simulation)', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $id = inventorySeed($product, 5);

    DB::table('inventories')->where('id', $id)->update(['is_baselined' => true]);
    DB::table('inventory_baselines')->insert(['inventory_id' => $id, 'kind' => 'adopted', 'on_hand_at_baseline' => 5, 'reserved_at_baseline' => 0, 'actor_type' => 'store_owner', 'actor_user_id' => $actor->userId, 'note' => 'n', 'created_at' => now()]);

    DB::listen(function (QueryExecuted $q) use ($id) {
        if (str_contains($q->sql, 'idempotency_key')) {
            Event::forget(QueryExecuted::class);
            DB::table('inventories')->where('id', $id)->update(['is_baselined' => false]);
        }
    });

    try {
        expect(fn () => ctl()->receive($product, 1, 'k', $actor, 'n'))->toThrow(InventoryNotBaselinedException::class);
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect(ctlMovements($id))->toBe(0);
});

it('refuses every control operation from anyone but the Store owner, before any write', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    $otherOwner = new StoreOwnerActor(Store::factory()->create()->user_id);
    $before = ctlMovements($id);

    expect(fn () => ctl()->receive($product, 1, 'k', $otherOwner, 'n'))->toThrow(InvalidInventoryOperationException::class, 'does not own')
        ->and(fn () => ctl()->writeOff($product, 1, 'k', $otherOwner, 'n'))->toThrow(InvalidInventoryOperationException::class, 'does not own')
        ->and(fn () => ctl()->correct($product, 1, $otherOwner, 'n'))->toThrow(InvalidInventoryOperationException::class, 'does not own')
        ->and(fn () => ctl()->adopt($product, $otherOwner, 'n'))->toThrow(InvalidInventoryOperationException::class, 'does not own')
        ->and(ctlMovements($id))->toBe($before);
});

it('refuses an invalid actor id and an operation on a Product with no Inventory', function () {
    $product = inventoryProduct(Store::factory()->create());

    expect(fn () => new StoreOwnerActor(0))->toThrow(InvalidInventoryOperationException::class)
        ->and(fn () => ctl()->receive($product, 1, 'k', inventoryActor($product), 'n'))->toThrow(InvalidInventoryOperationException::class, 'no Inventory');
});

it('keeps operating on a soft-deleted Product: stock is physical, only creation refuses it', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    $actor = inventoryActor($product);
    $product->delete();

    ctl()->receive($product->id, 2, 'late-delivery', $actor, 'n');
    ctl()->writeOff($product->id, 1, 'clearance', $actor, 'n');

    expect(ctlSeededInventoryState($id)['on_hand'])->toBe(6);
});

// ---------------------------------------------------------------------
// input: refused, never coerced
// ---------------------------------------------------------------------

it('refuses a quantity that is not a strict integer in range, never coercing it', function ($quantity) {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    $actor = inventoryActor($product);
    $before = ctlSeededInventoryState($id);

    expect(fn () => ctl()->receive($product, $quantity, 'k', $actor, 'n'))->toThrow(InvalidInventoryOperationException::class)
        ->and(fn () => ctl()->writeOff($product, $quantity, 'k', $actor, 'n'))->toThrow(InvalidInventoryOperationException::class)
        ->and(ctlSeededInventoryState($id))->toBe($before);
})->with([
    'zero' => [0], 'negative' => [-1], 'float' => [2.0], 'numeric string' => ['5'], 'bool' => [true], 'null' => [null],
    'above the ceiling' => [2147483648],
]);

it('accepts exactly the portable ceiling', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create());

    ctl()->receive($product, 2147483647, 'big', inventoryActor($product), 'n');

    expect(ctlSeededInventoryState($id)['on_hand'])->toBe(2147483647);
});

it('refuses a malformed idempotency key: rejected, never normalized', function (string $key) {
    [$product, $id] = inventoryGenesis(Store::factory()->create());
    $before = ctlMovements($id);

    expect(fn () => ctl()->receive($product, 1, $key, inventoryActor($product), 'n'))
        ->toThrow(InvalidInventoryOperationException::class, 'idempotency key')
        ->and(ctlMovements($id))->toBe($before);
})->with([
    'empty' => [''], 'uppercase' => ['ABC'], 'mixed case' => ['aBc'], 'leading dash' => ['-abc'], 'leading underscore' => ['_abc'],
    'space' => ['a b'], 'trailing space' => ['abc '], 'trailing newline' => ["abc\n"], 'dot' => ['a.b'], 'accent' => ['açúcar'],
    'colon' => ['a:b'], '65 chars' => [str_repeat('a', 65)],
]);

it('accepts the longest valid key', function () {
    [$product] = inventoryGenesis(Store::factory()->create());
    $key = str_repeat('a', 64);

    $result = ctl()->receive($product, 1, $key, inventoryActor($product), 'n');

    expect($result->movement->idempotency_key)->toBe('client:'.$key);
});

it('cannot forge a system identity: a caller key cannot contain a colon, and is always stored under client:', function (string $forged) {
    [$product] = inventoryGenesis(Store::factory()->create());

    expect(fn () => ctl()->receive($product, 1, $forged, inventoryActor($product), 'n'))
        ->toThrow(InvalidInventoryOperationException::class, 'idempotency key');
})->with(['sale:reservation:1', 'correction:movement:1', 'count:1', 'client:abc']);

it('refuses a blank or oversized note', function (string $note) {
    [$product, $id] = inventoryGenesis(Store::factory()->create());
    $actor = inventoryActor($product);
    $before = ctlMovements($id);

    expect(fn () => ctl()->receive($product, 1, 'k', $actor, $note))->toThrow(InvalidInventoryOperationException::class, 'note')
        ->and(fn () => ctl()->writeOff($product, 1, 'k', $actor, $note))->toThrow(InvalidInventoryOperationException::class, 'note')
        ->and(ctlMovements($id))->toBe($before);
})->with(['empty' => [''], 'spaces' => ['   '], 'newline' => ["\n"], 'too long' => [str_repeat('x', 2001)]]);

// ---------------------------------------------------------------------
// receive
// ---------------------------------------------------------------------

it('receives: on_hand rises, reserved does not move, and one receipt movement evidences it', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 10);
    $store = Store::query()->find($product->store_id);
    $actor = inventoryActor($product);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 4]]));

    $result = ctl()->receive($product, 5, 'delivery-1', $actor, 'delivery note 123');

    expect($result->replayed)->toBeFalse()
        ->and($result->movement->reason)->toBe(InventoryMovementReason::Receipt)
        ->and($result->movement->quantity_delta)->toBe(5)
        ->and($result->movement->idempotency_key)->toBe('client:delivery-1')
        ->and($result->movement->actor_type)->toBe(InventoryActorType::StoreOwner)
        ->and($result->movement->actor_user_id)->toBe($actor->userId)
        ->and($result->movement->note)->toBe('delivery note 123')
        ->and($result->movement->after_baseline)->toBeTrue()
        ->and(ctlSeededInventoryState($id))->toBe(['on_hand' => 15, 'reserved' => 4]);
});

it('replays an identical receipt: same row, zero second effect, even after stock has moved on', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create());
    $actor = inventoryActor($product);

    $first = ctl()->receive($product, 5, 'delivery', $actor, 'note');
    ctl()->writeOff($product, 2, 'broken', $actor, 'note');
    $second = ctl()->receive($product, 5, 'delivery', $actor, 'note');

    expect($second->replayed)->toBeTrue()
        ->and($second->movement->id)->toBe($first->movement->id)
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(3)
        ->and(ctlMovements($id))->toBe(2);
});

it('refuses the same key with a different request, with zero effect', function (string $difference) {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 10);
    $actor = inventoryActor($product);

    ctl()->receive($product, 5, 'delivery', $actor, 'note');
    $state = ctlSeededInventoryState($id);
    $movements = ctlMovements($id);

    $conflicting = match ($difference) {
        'quantity' => fn () => ctl()->receive($product, 6, 'delivery', $actor, 'note'),
        'note' => fn () => ctl()->receive($product, 5, 'delivery', $actor, 'other'),
        'reason' => fn () => ctl()->writeOff($product, 5, 'delivery', $actor, 'note'),
    };

    expect($conflicting)->toThrow(InventoryIdempotencyConflictException::class)
        ->and(ctlSeededInventoryState($id))->toBe($state)
        ->and(ctlMovements($id))->toBe($movements);
})->with(['quantity', 'note', 'reason']);

it('treats the same key on two Inventories as two operations: the scope is per Inventory', function () {
    $store = Store::factory()->create();
    [$a, $ia] = inventoryGenesis($store, 0, 'A');
    [$b, $ib] = inventoryGenesis($store, 0, 'B');

    ctl()->receive($a, 1, 'same', inventoryActor($a), 'n');
    ctl()->receive($b, 2, 'same', inventoryActor($b), 'n');

    expect(ctlSeededInventoryState($ia)['on_hand'])->toBe(1)
        ->and(ctlSeededInventoryState($ib)['on_hand'])->toBe(2);
});

it('refuses an increase past the BIGINT ceiling cleanly, with zero movement, and accepts exactly up to it', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $id = inventorySeed($product, PHP_INT_MAX - 5);
    ctl()->adopt($product, $actor, 'n');

    expect(fn () => ctl()->receive($product, 6, 'over', $actor, 'n'))->toThrow(InventoryQuantityCeilingException::class)
        ->and(ctlMovements($id))->toBe(0)
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(PHP_INT_MAX - 5);

    ctl()->receive($product, 5, 'exact', $actor, 'n');

    expect(ctlSeededInventoryState($id)['on_hand'])->toBe(PHP_INT_MAX);
});

it('decides the ceiling in the statement: its predicate carries the limit and no quantity is read to compare', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create());

    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql) {
        $sql[] = ['sql' => inventorySql($q->sql), 'bindings' => $q->bindings];
    });

    try {
        ctl()->receive($product, 3, 'k', inventoryActor($product), 'n');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    $updates = array_values(array_filter($sql, fn ($q) => (bool) preg_match('/^update "inventories" set "on_hand_quantity"/', $q['sql'])));

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['sql'])->toContain('and "on_hand_quantity" <= ?')
        ->and(end($updates[0]['bindings']))->toBe(PHP_INT_MAX - 3);
});

// ---------------------------------------------------------------------
// writeOff
// ---------------------------------------------------------------------

it('writes off available stock: one write_off movement, reserved untouched', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 10);
    $store = Store::query()->find($product->store_id);
    $actor = inventoryActor($product);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 4]]));

    $result = ctl()->writeOff($product, 6, 'broken', $actor, 'dropped');

    expect($result->movement->reason)->toBe(InventoryMovementReason::WriteOff)
        ->and($result->movement->quantity_delta)->toBe(-6)
        ->and(ctlSeededInventoryState($id))->toBe(['on_hand' => 4, 'reserved' => 4]);
});

it('never writes off reserved stock: one unit past available refuses, with zero movement and no reservation touched', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 10);
    $store = Store::query()->find($product->store_id);
    $actor = inventoryActor($product);
    $order = inventoryOrder($store, [[$product, 4]]);
    app(InventoryReservationService::class)->reserve($order);
    $movements = ctlMovements($id);

    expect(fn () => ctl()->writeOff($product, 7, 'too-much', $actor, 'n'))->toThrow(InsufficientStockException::class)
        ->and(ctlSeededInventoryState($id))->toBe(['on_hand' => 10, 'reserved' => 4])
        ->and(ctlMovements($id))->toBe($movements)
        ->and(DB::table('inventory_reservations')->where('status', 'reserved')->count())->toBe(1);
});

it('does not memoize a refused write-off: the same key re-evaluates later', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 1);
    $actor = inventoryActor($product);

    expect(fn () => ctl()->writeOff($product, 2, 'lost', $actor, 'n'))->toThrow(InsufficientStockException::class);

    ctl()->receive($product, 1, 'more', $actor, 'n');

    expect(ctl()->writeOff($product, 2, 'lost', $actor, 'n')->replayed)->toBeFalse()
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(0);
});

it('replays an identical write-off and refuses a different one', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    $actor = inventoryActor($product);

    $first = ctl()->writeOff($product, 2, 'lost', $actor, 'n');
    $second = ctl()->writeOff($product, 2, 'lost', $actor, 'n');

    expect($second->replayed)->toBeTrue()
        ->and($second->movement->id)->toBe($first->movement->id)
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(3)
        ->and(fn () => ctl()->writeOff($product, 3, 'lost', $actor, 'n'))->toThrow(InventoryIdempotencyConflictException::class);
});

// ---------------------------------------------------------------------
// correct
// ---------------------------------------------------------------------

it('corrects a receipt: the exact reversal, linked to its original, under the available guard', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create());
    $actor = inventoryActor($product);

    $original = ctl()->receive($product, 7, 'typo', $actor, 'n')->movement;
    $result = ctl()->correct($product, $original->id, $actor, 'should have been 5');

    expect($result->movement->reason)->toBe(InventoryMovementReason::Correction)
        ->and($result->movement->quantity_delta)->toBe(-7)
        ->and((int) $result->movement->corrects_movement_id)->toBe($original->id)
        ->and($result->movement->idempotency_key)->toBe('correction:movement:'.$original->id)
        ->and($result->movement->after_baseline)->toBeTrue()
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(0)
        ->and(InventoryMovement::query()->find($original->id)->quantity_delta)->toBe(7);
});

it('corrects a write-off: the exact reversal, under the overflow guard', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 10);
    $actor = inventoryActor($product);

    $original = ctl()->writeOff($product, 4, 'typo', $actor, 'n')->movement;
    $result = ctl()->correct($product, $original->id, $actor, 'not actually lost');

    expect($result->movement->quantity_delta)->toBe(4)
        ->and(ctlSeededInventoryState($id)['on_hand'])->toBe(10);
});

it('allows at most one correction per movement: an identical retry replays, a different one is refused', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 10);
    $actor = inventoryActor($product);
    $original = ctl()->receive($product, 3, 'typo', $actor, 'n')->movement;

    $first = ctl()->correct($product, $original->id, $actor, 'reason');
    $state = ctlSeededInventoryState($id);
    $second = ctl()->correct($product, $original->id, $actor, 'reason');

    expect($second->replayed)->toBeTrue()
        ->and($second->movement->id)->toBe($first->movement->id)
        ->and(ctlSeededInventoryState($id))->toBe($state)
        ->and(fn () => ctl()->correct($product, $original->id, $actor, 'another reason'))->toThrow(InventoryIdempotencyConflictException::class)
        ->and(DB::table('inventory_movements')->where('corrects_movement_id', $original->id)->count())->toBe(1);
});

it('never un-receives stock that has since been reserved: the reversal refuses, with zero movement', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create());
    $store = Store::query()->find($product->store_id);
    $actor = inventoryActor($product);
    $original = ctl()->receive($product, 5, 'delivery', $actor, 'n')->movement;
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 3]]));
    $movements = ctlMovements($id);

    expect(fn () => ctl()->correct($product, $original->id, $actor, 'n'))->toThrow(InsufficientStockException::class, 'reverse')
        ->and(ctlSeededInventoryState($id))->toBe(['on_hand' => 5, 'reserved' => 3])
        ->and(ctlMovements($id))->toBe($movements);
});

it('refuses to reverse a write-off past the BIGINT ceiling', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $actor = inventoryActor($product);
    $id = inventorySeed($product, 10);
    ctl()->adopt($product, $actor, 'n');
    $original = ctl()->writeOff($product, 5, 'lost', $actor, 'n')->movement;
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => PHP_INT_MAX - 1]);

    expect(fn () => ctl()->correct($product, $original->id, $actor, 'n'))->toThrow(InventoryQuantityCeilingException::class)
        ->and(DB::table('inventory_movements')->where('reason', 'correction')->count())->toBe(0);
});

it('never corrects a sale, a count adjustment or a correction', function () {
    [$product] = inventoryGenesis(Store::factory()->create(), 10);
    $store = Store::query()->find($product->store_id);
    $actor = inventoryActor($product);

    $order = inventoryOrder($store, [[$product, 1]]);
    $reservations = app(InventoryReservationService::class);
    $reservations->reserve($order);
    $reservations->commit($order);

    $sale = InventoryMovement::query()->where('reason', 'sale')->sole();
    $receipt = ctl()->receive($product, 1, 'r', $actor, 'n')->movement;
    $correction = ctl()->correct($product, $receipt->id, $actor, 'n')->movement;

    expect(fn () => ctl()->correct($product, $sale->id, $actor, 'n'))->toThrow(InvalidInventoryOperationException::class, 'never corrected')
        ->and(fn () => ctl()->correct($product, $correction->id, $actor, 'n'))->toThrow(InvalidInventoryOperationException::class, 'never corrected');
});

it('refuses a correction across Inventories, or of a movement that does not exist: wrong correlation fails closed', function () {
    $store = Store::factory()->create();
    [$a] = inventoryGenesis($store, 5, 'A');
    [$b] = inventoryGenesis($store, 5, 'B');
    $movement = ctl()->receive($a, 1, 'r', inventoryActor($a), 'n')->movement;

    expect(fn () => ctl()->correct($b, $movement->id, inventoryActor($b), 'n'))->toThrow(InvalidInventoryOperationException::class, 'is not a movement of')
        ->and(fn () => ctl()->correct($b, 999999, inventoryActor($b), 'n'))->toThrow(InvalidInventoryOperationException::class, 'is not a movement of');
});

// ---------------------------------------------------------------------
// what must not exist
// ---------------------------------------------------------------------

it('offers no setStock, adjust, incrementStock, decrementStock, restock or return operation', function () {
    $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass(InventoryControlService::class))->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->toEqualCanonicalizing(['__construct', 'createInventory', 'adopt', 'receive', 'writeOff', 'correct']);
});

it('never touches a reservation: control operations only ever issue UPDATEs on inventories', function () {
    [$product] = inventoryGenesis(Store::factory()->create(), 10);
    $store = Store::query()->find($product->store_id);
    $actor = inventoryActor($product);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 2]]));

    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes) {
        if (preg_match('/^(update|delete)/i', $q->sql)) {
            $writes[] = inventorySql($q->sql);
        }
    });

    try {
        $receipt = ctl()->receive($product, 1, 'a', $actor, 'n')->movement;
        ctl()->writeOff($product, 1, 'b', $actor, 'n');
        ctl()->correct($product, $receipt->id, $actor, 'n');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($writes)->toHaveCount(3);

    foreach ($writes as $sql) {
        expect($sql)->toStartWith('update "inventories" set "on_hand_quantity"');
    }
});
