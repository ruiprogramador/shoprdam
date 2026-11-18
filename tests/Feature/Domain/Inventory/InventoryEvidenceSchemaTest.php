<?php

use App\Domain\Inventory\Enums\InventoryCountStatus;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\InventoryBaseline;
use App\Domain\Inventory\Models\InventoryCount;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Services\InventoryControlService;
use App\Domain\Inventory\Services\InventoryCountService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Domain\Inventory\Support\InventoryRetry;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The evidence tables as the database itself defines them, attacked with raw
 * writes that bypass every model guard and service on purpose: what the database
 * refuses, what the models refuse, and how a refusal is classified. Engine-aware:
 * the default suite exercises the SQLite trigger forms, the real-engine runs of
 * this same file execute the named CHECKs and the real driver exceptions.
 */
function esMovement(int $inventoryId, array $overrides = []): array
{
    return [
        'inventory_id' => $inventoryId, 'reason' => 'receipt', 'quantity_delta' => 1, 'idempotency_key' => 'client:'.uniqid(),
        'reservation_id' => null, 'count_id' => null, 'corrects_movement_id' => null, 'actor_type' => 'store_owner', 'actor_user_id' => 1,
        'note' => 'n', 'after_baseline' => true, 'created_at' => now(),
        ...$overrides,
    ];
}

function esCount(int $inventoryId, array $overrides = []): array
{
    return [
        'inventory_id' => $inventoryId, 'idempotency_key' => 'client:'.uniqid(), 'expected_on_hand' => 5, 'observed_quantity' => 5,
        'on_hand_at_submission' => 5, 'reserved_at_submission' => 0, 'status' => 'applied', 'resolved_by_count_id' => null, 'resolved_at' => null,
        'actor_type' => 'store_owner', 'actor_user_id' => 1, 'note' => null, 'created_at' => now(),
        ...$overrides,
    ];
}

function esBaseline(int $inventoryId, array $overrides = []): array
{
    return [
        'inventory_id' => $inventoryId, 'kind' => 'adopted', 'on_hand_at_baseline' => 5, 'reserved_at_baseline' => 0, 'actor_type' => 'store_owner',
        'actor_user_id' => 1, 'note' => 'n', 'created_at' => now(),
        ...$overrides,
    ];
}

/** @return array{0: mixed, 1: int} */
function esInventory(int $stock = 5): array
{
    return inventoryGenesis(Store::factory()->create(), $stock);
}

function esViolation(Closure $operation): QueryException
{
    try {
        DB::transaction($operation);
    } catch (QueryException $e) {
        return $e;
    }

    throw new RuntimeException('The database accepted a write it had to refuse.');
}

it('marks every pre-existing row legacy through the column default, and backfills nothing', function () {
    $product = inventoryProduct(Store::factory()->create());
    $id = DB::table('inventories')->insertGetId([
        'product_id' => $product->id, 'on_hand_quantity' => 5, 'reserved_quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect((bool) DB::table('inventories')->where('id', $id)->value('is_baselined'))->toBeFalse()
        ->and(DB::table('inventory_baselines')->count())->toBe(0)
        ->and(DB::table('inventory_movements')->count())->toBe(0);
});

it('starts empty: no evidence is ever fabricated by a migration', function () {
    expect(DB::table('inventory_movements')->count())->toBe(0)
        ->and(DB::table('inventory_counts')->count())->toBe(0)
        ->and(DB::table('inventory_baselines')->count())->toBe(0);
});

it('stores no history-erasing column on the evidence tables', function () {
    foreach (['inventory_movements', 'inventory_counts', 'inventory_baselines'] as $table) {
        expect(Schema::hasColumn($table, 'deleted_at'))->toBeFalse($table)
            ->and(Schema::hasColumn($table, 'updated_at'))->toBeFalse($table);
    }
});

it('refuses a movement whose sign, correlation, actor, note or stamp contradicts its reason', function (array $overrides) {
    [, $id] = esInventory();

    expectDatabaseRefusal(fn () => DB::table('inventory_movements')->insert(esMovement($id, $overrides)));

    expect(DB::table('inventory_movements')->where('inventory_id', $id)->where('note', '<>', 'first delivery')->count())->toBe(0);
})->with([
    'zero delta' => [['quantity_delta' => 0]],
    'negative receipt' => [['quantity_delta' => -1]],
    'positive write-off' => [['reason' => 'write_off', 'quantity_delta' => 1]],
    'unknown reason' => [['reason' => 'restock']],
    'receipt with a correlation' => [['count_id' => null, 'corrects_movement_id' => 1]],
    'sale with no reservation' => [['reason' => 'sale', 'quantity_delta' => -1, 'actor_type' => 'system', 'actor_user_id' => null]],
    'count adjustment with no count' => [['reason' => 'count_adjustment']],
    'correction with no original' => [['reason' => 'correction']],
    'system actor on a receipt' => [['actor_type' => 'system', 'actor_user_id' => null]],
    'no actor id' => [['actor_user_id' => null]],
    'unknown actor type' => [['actor_type' => 'admin']],
    'receipt with no note' => [['note' => null]],
    'non-sale before a baseline' => [['after_baseline' => false]],
    'null identity' => [['idempotency_key' => null]],
    'null stamp' => [['after_baseline' => null]],
]);

it('refuses a sale carrying a human actor, and accepts the correct shape', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 5);
    $service = app(InventoryReservationService::class);
    $order = inventoryOrder($store, [[$product, 1]]);
    $service->reserve($order);
    $reservationId = (int) DB::table('inventory_reservations')->value('id');

    expectDatabaseRefusal(fn () => DB::table('inventory_movements')->insert(esMovement($id, ['reason' => 'sale', 'quantity_delta' => -1, 'reservation_id' => $reservationId, 'note' => null])));

    DB::table('inventory_movements')->insert(esMovement($id, [
        'reason' => 'sale', 'quantity_delta' => -1, 'reservation_id' => $reservationId, 'actor_type' => 'system', 'actor_user_id' => null, 'note' => null, 'after_baseline' => false,
    ]));

    expect(DB::table('inventory_movements')->where('reason', 'sale')->count())->toBe(1);
});

it('refuses a duplicate movement identity on one Inventory and accepts it on another: nothing depends on NULL semantics', function () {
    [, $a] = esInventory();
    [, $b] = esInventory();

    DB::table('inventory_movements')->insert(esMovement($a, ['idempotency_key' => 'client:same']));

    expectDatabaseRefusal(fn () => DB::table('inventory_movements')->insert(esMovement($a, ['idempotency_key' => 'client:same'])));

    DB::table('inventory_movements')->insert(esMovement($b, ['idempotency_key' => 'client:same']));

    expect(DB::table('inventory_movements')->where('idempotency_key', 'client:same')->count())->toBe(2);
});

it('refuses a count whose stored facts contradict its status', function (array $overrides) {
    [, $id] = esInventory();

    expectDatabaseRefusal(fn () => DB::table('inventory_counts')->insert(esCount($id, $overrides)));

    expect(DB::table('inventory_counts')->count())->toBe(0);
})->with([
    'stale but the snapshot matched' => [['status' => 'stale']],
    'applied but the snapshot did not match' => [['expected_on_hand' => 4]],
    'applied below reserved' => [['observed_quantity' => 1, 'reserved_at_submission' => 2]],
    'open but not below reserved' => [['status' => 'discrepancy_open']],
    'resolved with no resolver' => [['status' => 'discrepancy_resolved', 'observed_quantity' => 1, 'reserved_at_submission' => 2]],
    'open carrying a resolution time' => [['status' => 'discrepancy_open', 'observed_quantity' => 1, 'reserved_at_submission' => 2, 'resolved_at' => '2026-01-01 00:00:00']],
    'unknown status' => [['status' => 'dismissed']],
    'negative observation' => [['observed_quantity' => -1]],
    'negative snapshot' => [['expected_on_hand' => -1, 'status' => 'stale']],
    'reserved above on hand' => [['reserved_at_submission' => 6]],
    'unknown actor type' => [['actor_type' => 'system']],
    'no actor id' => [['actor_user_id' => null]],
]);

it('refuses a baseline that contradicts its kind, and a second baseline for one Inventory', function (array $overrides) {
    $product = inventoryProduct(Store::factory()->create());
    $id = inventorySeed($product, 5);

    expectDatabaseRefusal(fn () => DB::table('inventory_baselines')->insert(esBaseline($id, $overrides)));

    expect(DB::table('inventory_baselines')->count())->toBe(0);
})->with([
    'genesis with stock' => [['kind' => 'genesis']],
    'genesis with reservations' => [['kind' => 'genesis', 'on_hand_at_baseline' => 0, 'reserved_at_baseline' => 1]],
    'adopted with no note' => [['note' => null]],
    'adopted over-reserved' => [['reserved_at_baseline' => 6]],
    'adopted negative' => [['on_hand_at_baseline' => -1]],
    'unknown kind' => [['kind' => 'legacy']],
    'unknown actor type' => [['actor_type' => 'system']],
    'no actor id' => [['actor_user_id' => null]],
]);

it('keeps at most one baseline per Inventory, whatever its kind', function () {
    [, $id] = esInventory();

    expectDatabaseRefusal(fn () => DB::table('inventory_baselines')->insert(esBaseline($id)));

    expect(DB::table('inventory_baselines')->where('inventory_id', $id)->count())->toBe(1);
});

it('refuses to delete what history references: Inventory, Product, committed reservation, count, corrected movement', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 10);
    $actor = inventoryActor($product);

    $reservations = app(InventoryReservationService::class);
    $order = inventoryOrder($store, [[$product, 1]]);
    $reservations->reserve($order);
    $reservations->commit($order);

    $receipt = app(InventoryControlService::class)->receive($product, 1, 'r', $actor, 'n')->movement;
    app(InventoryControlService::class)->correct($product, $receipt->id, $actor, 'n');
    $count = app(InventoryCountService::class)->submitCount($product, 9, 8, 'c', $actor);

    expectDatabaseRefusal(fn () => DB::table('inventories')->where('id', $id)->delete());
    expectDatabaseRefusal(fn () => DB::table('products')->where('id', $product->id)->delete());
    expectDatabaseRefusal(fn () => DB::table('inventory_reservations')->delete());
    expectDatabaseRefusal(fn () => DB::table('inventory_counts')->where('id', $count->count->id)->delete());
    expectDatabaseRefusal(fn () => DB::table('inventory_movements')->where('id', $receipt->id)->delete());

    expect(DB::table('inventories')->where('id', $id)->count())->toBe(1)
        ->and(DB::table('inventory_counts')->count())->toBe(1);
});

it('refuses to delete a count that resolved a discrepancy', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 10);
    $actor = inventoryActor($product);
    $counts = app(InventoryCountService::class);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 7]]));

    $counts->submitCount($product, 10, 5, 'short', $actor);
    $resolver = $counts->submitCount($product, 10, 10, 'fine', $actor);

    expectDatabaseRefusal(fn () => DB::table('inventory_counts')->where('id', $resolver->count->id)->delete());

    expect(DB::table('inventory_counts')->count())->toBe(2);
});

it('declares every evidence foreign key RESTRICT, and none to users', function () {
    foreach (['inventory_movements', 'inventory_counts', 'inventory_baselines'] as $table) {
        $keys = dbForeignKeyInfo($table);

        expect($keys)->not->toBe([]);

        foreach ($keys as $column => $key) {
            expect(strtoupper($key['rule']))->toBe('RESTRICT', "{$table}.{$column}")
                ->and($key['table'])->not->toBe('users');
        }
    }

    expect(array_keys(dbForeignKeyInfo('inventory_movements')))->toEqualCanonicalizing(['inventory_id', 'reservation_id', 'count_id', 'corrects_movement_id'])
        ->and(array_keys(dbForeignKeyInfo('inventory_counts')))->toEqualCanonicalizing(['inventory_id', 'resolved_by_count_id'])
        ->and(array_keys(dbForeignKeyInfo('inventory_baselines')))->toBe(['inventory_id']);
});

it('keeps stock history intact and unmodified when the acting User is deleted: the actor id is a snapshot, not a reference', function () {
    [$product, $id] = esInventory(5);
    $departed = User::factory()->create()->id;

    DB::table('inventory_movements')->insert(esMovement($id, ['actor_user_id' => $departed, 'idempotency_key' => 'client:departed', 'after_baseline' => true]));

    DB::table('users')->where('id', $departed)->delete();

    expect(DB::table('users')->where('id', $departed)->exists())->toBeFalse()
        ->and((int) DB::table('inventory_movements')->where('idempotency_key', 'client:departed')->value('actor_user_id'))->toBe($departed);
});

// ---------------------------------------------------------------------
// Model guards
// ---------------------------------------------------------------------

it('refuses every Eloquent write on a movement', function (Closure $attempt) {
    [$product] = esInventory();
    $movement = InventoryMovement::query()->firstOrFail();
    $original = $movement->quantity_delta;

    expect(esRefused(fn () => $attempt($movement, $product)))->toBeTrue()
        ->and(InventoryMovement::query()->find($movement->id)->quantity_delta)->toBe($original)
        ->and(InventoryMovement::query()->count())->toBe(1);
})->with(fn () => esGuardAttempts('quantity_delta', 99));

it('refuses every Eloquent write on a count', function (Closure $attempt) {
    [$product] = esInventory();
    $count = app(InventoryCountService::class)->submitCount($product, 5, 5, 'c', inventoryActor($product))->count;

    expect(esRefused(fn () => $attempt($count, $product)))->toBeTrue()
        ->and(InventoryCount::query()->find($count->id)->status)->toBe(InventoryCountStatus::Applied)
        ->and(InventoryCount::query()->count())->toBe(1);
})->with(fn () => esGuardAttempts('status', InventoryCountStatus::Stale));

it('refuses every Eloquent write on a baseline', function (Closure $attempt) {
    [$product] = esInventory();
    $baseline = InventoryBaseline::query()->firstOrFail();

    expect(esRefused(fn () => $attempt($baseline, $product)))->toBeTrue()
        ->and(InventoryBaseline::query()->count())->toBe(1);
})->with(fn () => esGuardAttempts('note', 'rewritten'));

/** A model guard refuses with a LogicException, an empty $fillable with a MassAssignmentException. */
function esRefused(Closure $write): bool
{
    try {
        $write();
    } catch (LogicException|MassAssignmentException) {
        return true;
    }

    return false;
}

function esGuardAttempts(string $column, mixed $value): array
{
    return [
        'update' => [fn ($m) => $m->update([$column => $value])],
        'forceFill + save' => [fn ($m) => $m->forceFill([$column => $value])->save()],
        'attribute + save' => [function ($m) use ($column, $value) {
            $m->{$column} = $value;
            $m->save();
        }],
        'saveQuietly' => [function ($m) use ($column, $value) {
            $m->{$column} = $value;
            $m->saveQuietly();
        }],
        'updateQuietly' => [fn ($m) => $m->forceFill([$column => $value])->updateQuietly([$column => $value])],
        'withoutEvents' => [function ($m) use ($column, $value) {
            $m::withoutEvents(function () use ($m, $column, $value) {
                $m->{$column} = $value;
                $m->save();
            });
        }],
        'push' => [function ($m) use ($column, $value) {
            $m->{$column} = $value;
            $m->push();
        }],
        'increment' => [fn ($m) => $m->increment('id')],
        'delete' => [fn ($m) => $m->delete()],
        'deleteQuietly' => [fn ($m) => $m->deleteQuietly()],
        'forceDelete' => [fn ($m) => $m->forceDelete()],
        'destroy' => [fn ($m) => $m::destroy($m->getKey())],
        'replicate + save' => [fn ($m) => $m->replicate()->save()],
        'a brand new instance' => [function ($m) use ($column, $value) {
            $new = new $m;
            $new->{$column} = $value;
            $new->save();
        }],
    ];
}

it('refuses creation through a relation', function () {
    [$product, $id] = esInventory();
    $inventory = Inventory::query()->findOrFail($id);
    $before = [InventoryMovement::query()->count(), InventoryCount::query()->count(), InventoryBaseline::query()->count()];

    expect(fn () => $inventory->movements()->save(new InventoryMovement))->toThrow(LogicException::class)
        ->and(fn () => $inventory->counts()->save(new InventoryCount))->toThrow(LogicException::class)
        ->and(fn () => $inventory->baseline()->save(new InventoryBaseline))->toThrow(LogicException::class)
        ->and([InventoryMovement::query()->count(), InventoryCount::query()->count(), InventoryBaseline::query()->count()])->toBe($before);
});

it('offers no mass-assignment surface on the evidence models', function () {
    foreach ([new InventoryMovement, new InventoryCount, new InventoryBaseline] as $model) {
        expect($model->getFillable())->toBe([]);
    }
});

it('defines exactly the five approved reasons, and exactly two correctable ones', function () {
    expect(array_column(InventoryMovementReason::cases(), 'value'))->toBe(['receipt', 'sale', 'write_off', 'count_adjustment', 'correction'])
        ->and(array_values(array_filter(InventoryMovementReason::cases(), fn ($r) => $r->isCorrectable())))
        ->toBe([InventoryMovementReason::Receipt, InventoryMovementReason::WriteOff]);
});

// ---------------------------------------------------------------------
// Classification, against real driver exceptions of whichever engine runs this
// ---------------------------------------------------------------------

it('classifies a lost identity claim of its own constraint, and nothing else', function () {
    [, $id] = esInventory();
    DB::table('inventory_movements')->insert(esMovement($id, ['idempotency_key' => 'client:same']));
    DB::table('inventory_counts')->insert(esCount($id, ['idempotency_key' => 'client:same']));

    $movement = esViolation(fn () => DB::table('inventory_movements')->insert(esMovement($id, ['idempotency_key' => 'client:same'])));
    $count = esViolation(fn () => DB::table('inventory_counts')->insert(esCount($id, ['idempotency_key' => 'client:same'])));
    $baseline = esViolation(fn () => DB::table('inventory_baselines')->insert(esBaseline($id)));

    $movementRule = ['inventory_movements_idempotency_unique', 'inventory_movements.idempotency_key'];
    $countRule = ['inventory_counts_idempotency_unique', 'inventory_counts.idempotency_key'];
    $baselineRule = ['inventory_baselines_inventory_unique', 'inventory_baselines.inventory_id'];

    expect(InventoryRetry::isUniqueViolationOf($movement, ...$movementRule))->toBeTrue()
        ->and(InventoryRetry::isUniqueViolationOf($movement, ...$countRule))->toBeFalse()
        ->and(InventoryRetry::isUniqueViolationOf($movement, ...$baselineRule))->toBeFalse()
        ->and(InventoryRetry::isUniqueViolationOf($count, ...$countRule))->toBeTrue()
        ->and(InventoryRetry::isUniqueViolationOf($count, ...$movementRule))->toBeFalse()
        ->and(InventoryRetry::isUniqueViolationOf($baseline, ...$baselineRule))->toBeTrue()
        ->and(InventoryRetry::isUniqueViolationOf($baseline, ...$movementRule))->toBeFalse();
});

it('never classifies a foreign-key, CHECK or NOT NULL violation as the expected race', function () {
    [, $id] = esInventory();
    $rule = ['inventory_movements_idempotency_unique', 'inventory_movements.idempotency_key'];

    $foreignKey = esViolation(fn () => DB::table('inventory_movements')->insert(esMovement(999999)));
    $check = esViolation(fn () => DB::table('inventory_movements')->insert(esMovement($id, ['quantity_delta' => 0])));
    $notNull = esViolation(fn () => DB::table('inventory_movements')->insert(esMovement($id, ['idempotency_key' => null])));

    expect(InventoryRetry::isUniqueViolationOf($foreignKey, ...$rule))->toBeFalse()
        ->and(InventoryRetry::isUniqueViolationOf($check, ...$rule))->toBeFalse()
        ->and(InventoryRetry::isUniqueViolationOf($notNull, ...$rule))->toBeFalse()
        ->and(InventoryRetry::isMariadbSnapshotConflict($foreignKey))->toBeFalse()
        ->and(InventoryRetry::isMariadbSnapshotConflict($check))->toBeFalse();
});

it('is not spoofed by a value echoed in the error message', function () {
    [, $id] = esInventory();
    $key = 'client:inventory_counts_idempotency_unique';

    DB::table('inventory_movements')->insert(esMovement($id, ['idempotency_key' => $key]));

    $violation = esViolation(fn () => DB::table('inventory_movements')->insert(esMovement($id, ['idempotency_key' => $key])));

    expect(InventoryRetry::isUniqueViolationOf($violation, 'inventory_counts_idempotency_unique', 'inventory_counts.idempotency_key'))->toBeFalse()
        ->and(InventoryRetry::isUniqueViolationOf($violation, 'inventory_movements_idempotency_unique', 'inventory_movements.idempotency_key'))->toBeTrue();
});

it('retries only its own lost claim and the MariaDB snapshot conflict, and only a bounded number of times', function () {
    [, $id] = esInventory();
    DB::table('inventory_movements')->insert(esMovement($id, ['idempotency_key' => 'client:same']));

    $own = esViolation(fn () => DB::table('inventory_movements')->insert(esMovement($id, ['idempotency_key' => 'client:same'])));
    $other = esViolation(fn () => DB::table('inventory_movements')->insert(esMovement($id, ['quantity_delta' => 0])));

    $attempts = 0;

    try {
        InventoryRetry::run(function () use (&$attempts, $own) {
            $attempts++;

            throw $own;
        }, fn () => true);
    } catch (QueryException) {
    }

    expect($attempts)->toBe(InventoryRetry::MAX_ATTEMPTS);

    $attempts = 0;

    try {
        InventoryRetry::run(function () use (&$attempts, $other) {
            $attempts++;

            throw $other;
        }, fn () => false);
    } catch (QueryException) {
    }

    expect($attempts)->toBe(1);

    $attempts = 0;

    expect(InventoryRetry::run(function () use (&$attempts, $own) {
        if (++$attempts === 1) {
            throw $own;
        }

        return 'converged';
    }, fn () => true))->toBe('converged')
        ->and($attempts)->toBe(2);
});
