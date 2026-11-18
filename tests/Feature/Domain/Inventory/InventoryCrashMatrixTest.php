<?php

use App\Domain\Inventory\Services\InventoryControlService;
use App\Domain\Inventory\Services\InventoryCountService;
use App\Domain\Inventory\Services\InventoryIntegrityChecker;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Store;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The crash matrix of docs/inventory/INVENTORY-CONTROL.md §11. A listener throws
 * right after one exact statement runs, which is what a process death at that
 * point leaves behind once the transaction is rolled back: the claim was written,
 * the counter was not, or the other way round. Each case then proves two things:
 * nothing partial survives, and the same request retried applies exactly once.
 *
 * This is a simulation on one connection. Real process deaths holding real locks
 * are in tests/Concurrency.
 */
function crashState(): array
{
    return [
        'inventories' => DB::table('inventories')->orderBy('id')->get(['id', 'on_hand_quantity', 'reserved_quantity', 'is_baselined'])->map(fn ($r) => (array) $r)->all(),
        'movements' => DB::table('inventory_movements')->count(),
        'counts' => DB::table('inventory_counts')->count(),
        'baselines' => DB::table('inventory_baselines')->count(),
        'statuses' => DB::table('inventory_reservations')->orderBy('id')->pluck('status')->all(),
    ];
}

/** Runs $operation with a process "death" right after the first statement matching $pattern. */
function crashAfter(string $pattern, Closure $operation): void
{
    $fired = false;

    DB::listen(function (QueryExecuted $q) use ($pattern, &$fired) {
        if (! $fired && preg_match($pattern, inventorySql($q->sql))) {
            $fired = true;

            throw new RuntimeException('simulated crash');
        }
    });

    try {
        expect($operation)->toThrow(RuntimeException::class, 'simulated crash');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($fired)->toBeTrue("The crash point {$pattern} was never reached.");
}

/**
 * @return array{0: mixed, 1: Closure, 2: Closure} the operation, and what must hold after it applied once
 */
function crashFamily(string $family): array
{
    $store = Store::factory()->create();
    $control = app(InventoryControlService::class);
    $counts = app(InventoryCountService::class);
    $reservations = app(InventoryReservationService::class);

    switch ($family) {
        case 'receipt':
            [$product] = inventoryGenesis($store, 5);

            return [
                fn () => $control->receive($product, 3, 'crash-key', inventoryActor($product), 'n'),
                function () use ($product) {
                    expect(inventoryState($product)['on_hand'])->toBe(8)
                        ->and(DB::table('inventory_movements')->where('idempotency_key', 'client:crash-key')->count())->toBe(1);
                },
            ];
        case 'write_off':
            [$product] = inventoryGenesis($store, 5);

            return [
                fn () => $control->writeOff($product, 3, 'crash-key', inventoryActor($product), 'n'),
                function () use ($product) {
                    expect(inventoryState($product)['on_hand'])->toBe(2)
                        ->and(DB::table('inventory_movements')->where('idempotency_key', 'client:crash-key')->count())->toBe(1);
                },
            ];
        case 'correction':
            [$product] = inventoryGenesis($store, 5);
            $movement = $control->receive($product, 2, 'mistake', inventoryActor($product), 'n')->movement;

            return [
                fn () => $control->correct($product, $movement->id, inventoryActor($product), 'n'),
                function () use ($product, $movement) {
                    expect(inventoryState($product)['on_hand'])->toBe(5)
                        ->and(DB::table('inventory_movements')->where('corrects_movement_id', $movement->id)->count())->toBe(1);
                },
            ];
        case 'count':
            [$product] = inventoryGenesis($store, 10);
            $reservations->reserve(inventoryOrder($store, [[$product, 7]]));
            $open = $counts->submitCount($product, 10, 5, 'short', inventoryActor($product))->count;

            return [
                fn () => $counts->submitCount($product, 10, 8, 'crash-key', inventoryActor($product)),
                function () use ($product, $open) {
                    expect(inventoryState($product)['on_hand'])->toBe(8)
                        ->and(DB::table('inventory_counts')->where('idempotency_key', 'client:crash-key')->count())->toBe(1)
                        ->and(DB::table('inventory_movements')->where('reason', 'count_adjustment')->count())->toBe(1)
                        ->and($open->fresh()->status->value)->toBe('discrepancy_resolved');
                },
            ];
        case 'commit':
            [$product] = inventoryGenesis($store, 5);
            $order = inventoryOrder($store, [[$product, 2]]);
            $reservations->reserve($order);

            return [
                fn () => $reservations->commit($order),
                function () use ($product) {
                    expect(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3])
                        ->and(DB::table('inventory_movements')->where('reason', 'sale')->count())->toBe(1)
                        ->and(DB::table('inventory_reservations')->value('status'))->toBe('committed');
                },
            ];
        case 'create':
            $product = inventoryProduct($store);

            return [
                fn () => $control->createInventory($product, inventoryActor($product)),
                function () use ($product) {
                    expect(DB::table('inventories')->where('product_id', $product->id)->where('is_baselined', true)->count())->toBe(1)
                        ->and(DB::table('inventory_baselines')->where('kind', 'genesis')->count())->toBe(1);
                },
            ];
        case 'adopt':
            $product = inventoryProduct($store);
            inventorySeed($product, 5);

            return [
                fn () => $control->adopt($product, inventoryActor($product), 'n'),
                function () use ($product) {
                    expect(DB::table('inventories')->where('product_id', $product->id)->where('is_baselined', true)->count())->toBe(1)
                        ->and(DB::table('inventory_baselines')->where('kind', 'adopted')->count())->toBe(1)
                        ->and(inventoryState($product)['on_hand'])->toBe(5);
                },
            ];
    }

    throw new LogicException($family);
}

it('CP1-CP4: leaves nothing partial at any statement, and the retry applies exactly once', function (string $family, string $crashPoint) {
    [$operation, $appliedOnce] = crashFamily($family);
    $before = crashState();

    crashAfter($crashPoint, $operation);

    expect(crashState())->toBe($before);

    $operation();

    $appliedOnce();
    expect((new InventoryIntegrityChecker)->audit())->toBe([]);
})->with([
    'receipt, CP1 after the lock' => ['receipt', '/^select .*"is_baselined".* from "inventories"/'],
    'receipt, CP2 after the claim, before the counter' => ['receipt', '/^insert into "inventory_movements"/'],
    'receipt, CP3 after the counter' => ['receipt', '/^update "inventories" set "on_hand_quantity"/'],

    'write-off, CP1 after the lock' => ['write_off', '/^select .*"is_baselined".* from "inventories"/'],
    'write-off, CP2 after the claim, before the counter' => ['write_off', '/^insert into "inventory_movements"/'],
    'write-off, CP3 after the counter' => ['write_off', '/^update "inventories" set "on_hand_quantity"/'],

    'correction, CP1 after the lock' => ['correction', '/^select .*"is_baselined".* from "inventories"/'],
    'correction, CP2 after the claim, before the counter' => ['correction', '/^insert into "inventory_movements"/'],
    'correction, CP3 after the counter' => ['correction', '/^update "inventories" set "on_hand_quantity"/'],

    'count, CP1 after the lock' => ['count', '/^select .*"is_baselined".* from "inventories"/'],
    'count, CP2 after the claim, before the counter' => ['count', '/^insert into "inventory_counts"/'],
    'count, CP3 counter done, movement not' => ['count', '/^update "inventories" set "on_hand_quantity"/'],
    'count, CP4 movement done, resolution not' => ['count', '/^insert into "inventory_movements"/'],
    'count, CP4 resolution done' => ['count', '/^update "inventory_counts"/'],

    'commit, CP1 after the lock' => ['commit', '/^select "id" from "inventories"/'],
    'commit, CP2 after the CAS, before the counter' => ['commit', '/^update "inventory_reservations"/'],
    'commit, CP3 counter done, evidence not' => ['commit', '/^update "inventories" set "on_hand_quantity"/'],
    'commit, CP3 after the stamp read' => ['commit', '/^select "is_baselined" from "inventories"/'],
    'commit, CP4 after the sale insert' => ['commit', '/^insert into "inventory_movements"/'],

    'create, CP2 after the claim' => ['create', '/^insert into "inventories"/'],
    'create, CP4 after the baseline' => ['create', '/^insert into "inventory_baselines"/'],

    'adopt, CP1 after the lock' => ['adopt', '/^select .*"is_baselined".* from "inventories"/'],
    'adopt, CP2 after the claim' => ['adopt', '/^insert into "inventory_baselines"/'],
    'adopt, CP4 after the flag' => ['adopt', '/^update "inventories"/'],
]);

it('CP0: a crash before the transaction wrote nothing, and the retry is simply a first attempt', function (string $family) {
    [$operation, $appliedOnce] = crashFamily($family);
    $before = crashState();

    crashAfter('/^select .* from "(stores|inventories|inventory_reservations|order_items|products)"/', $operation);

    expect(crashState())->toBe($before);

    $operation();

    $appliedOnce();
})->with(['receipt', 'write_off', 'correction', 'count', 'commit', 'create', 'adopt']);

it('CP5/CP6: an unknown outcome is resolved by retrying: committed, the retry has zero second effect', function (string $family) {
    [$operation, $appliedOnce] = crashFamily($family);

    $operation();
    $appliedOnce();
    $after = crashState();

    // The response was lost, or COMMIT's outcome is unknown: the caller retries, twice.
    $operation();
    $operation();

    expect(crashState())->toBe($after);
    $appliedOnce();
})->with(['receipt', 'write_off', 'correction', 'count', 'commit', 'create', 'adopt']);

it('CP5: an unknown outcome is resolved by retrying: not committed, the retry applies once', function (string $family) {
    [$operation, $appliedOnce] = crashFamily($family);

    // The outcome the caller could not see was a rollback.
    try {
        DB::transaction(function () use ($operation) {
            $operation();

            throw new RuntimeException('COMMIT never reached the server');
        });
    } catch (RuntimeException) {
    }

    $operation();

    $appliedOnce();
})->with(['receipt', 'write_off', 'correction', 'count', 'commit', 'create', 'adopt']);

it('CP6 keeps a stored outcome through a retry: stale and discrepancy are not re-decided', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 10);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 7]]));
    $counts = app(InventoryCountService::class);
    $actor = inventoryActor($product);

    $stale = $counts->submitCount($product, 9, 8, 'stale', $actor);
    $open = $counts->submitCount($product, 10, 5, 'open', $actor);
    $before = crashState();

    expect($counts->submitCount($product, 9, 8, 'stale', $actor)->count->id)->toBe($stale->count->id)
        ->and($counts->submitCount($product, 10, 5, 'open', $actor)->count->status->value)->toBe('discrepancy_open')
        ->and($open->count->status->value)->toBe('discrepancy_open')
        ->and(crashState())->toBe($before);
});

it('never compensates: a failed operation issues no repairing write of its own, rollback is the compensation', function () {
    [$product] = inventoryGenesis(Store::factory()->create(), 5);

    $writes = [];

    DB::listen(function (QueryExecuted $q) use (&$writes) {
        if (preg_match('/^(update|insert|delete)/i', $q->sql)) {
            $writes[] = inventorySql($q->sql);
        }

        if (str_starts_with(inventorySql($q->sql), 'update "inventories" set "on_hand_quantity"')) {
            throw new RuntimeException('simulated crash');
        }
    });

    try {
        expect(fn () => app(InventoryControlService::class)->writeOff($product, 1, 'k', inventoryActor($product), 'n'))->toThrow(RuntimeException::class);
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($writes)->toHaveCount(2)
        ->and($writes[0])->toStartWith('insert into "inventory_movements"')
        ->and($writes[1])->toStartWith('update "inventories" set "on_hand_quantity"');
});
