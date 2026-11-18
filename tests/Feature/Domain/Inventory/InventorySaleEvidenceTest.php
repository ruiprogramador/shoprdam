<?php

use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use App\Domain\Inventory\Exceptions\InvalidReservationException;
use App\Domain\Inventory\Exceptions\InventoryIntegrityException;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Services\InventoryControlService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Store;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The one deliberate change to a commit (docs/inventory/INVENTORY-CONTROL.md
 * §6.4): winning the reservation's compare-and-set, the existing counter
 * statement and the sale movement are one transaction. When commit is called
 * relative to payment stays BLOCKED: nothing here wires it.
 */
function sale(): InventoryReservationService
{
    return app(InventoryReservationService::class);
}

function saleMovements(): int
{
    return DB::table('inventory_movements')->where('reason', 'sale')->count();
}

it('records one sale movement per committed line, correlated to its reservation', function () {
    $store = Store::factory()->create();
    [$a, $ia] = inventoryGenesis($store, 10, 'A');
    [$b, $ib] = inventoryGenesis($store, 10, 'B');
    $order = inventoryOrder($store, [[$a, 2], [$b, 3]]);

    sale()->reserve($order);
    sale()->commit($order);

    $reservations = InventoryReservation::query()->orderBy('inventory_id')->get()->keyBy('inventory_id');
    $movements = InventoryMovement::query()->where('reason', 'sale')->get()->keyBy('inventory_id');

    expect($movements)->toHaveCount(2)
        ->and($movements[$ia]->quantity_delta)->toBe(-2)
        ->and($movements[$ib]->quantity_delta)->toBe(-3)
        ->and((int) $movements[$ia]->reservation_id)->toBe($reservations[$ia]->id)
        ->and($movements[$ia]->idempotency_key)->toBe('sale:reservation:'.$reservations[$ia]->id)
        ->and($movements[$ia]->reason)->toBe(InventoryMovementReason::Sale)
        ->and($movements[$ia]->actor_type)->toBe(InventoryActorType::System)
        ->and($movements[$ia]->actor_user_id)->toBeNull()
        ->and($movements[$ia]->after_baseline)->toBeTrue()
        ->and($movements[$ia]->created_at->equalTo($reservations[$ia]->committed_at))->toBeTrue()
        ->and(inventoryState($a))->toBe(['on_hand' => 8, 'reserved' => 0, 'available' => 8]);
});

it('leaves the existing commit semantics exactly as they were: on_hand and reserved drop together, the reservation is terminal', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    sale()->reserve($order);

    expect(sale()->commit($order))->toBe(1)
        ->and(inventoryState($product))->toBe(['on_hand' => 3, 'reserved' => 0, 'available' => 3])
        ->and(DB::table('inventory_reservations')->value('status'))->toBe('committed');
});

it('has zero second effect on a duplicate commit: no counter change and no second movement', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    sale()->reserve($order);
    sale()->commit($order);

    expect(sale()->commit($order))->toBe(0)
        ->and(saleMovements())->toBe(1)
        ->and(inventoryState($product)['on_hand'])->toBe(3);
});

it('records no movement for reserve or release: on_hand did not change', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 5);
    $before = DB::table('inventory_movements')->count();
    $order = inventoryOrder($store, [[$product, 2]]);

    sale()->reserve($order);
    sale()->release($order);

    expect(DB::table('inventory_movements')->count())->toBe($before);
});

it('stamps a sale on an unbaselined legacy Inventory after_baseline = false: commit is not narrowed', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 2]]);

    sale()->reserve($order);

    expect(sale()->commit($order))->toBe(1)
        ->and(InventoryMovement::query()->where('reason', 'sale')->sole()->after_baseline)->toBeFalse()
        ->and(inventoryState($product)['on_hand'])->toBe(3);
});

it('stamps a sale on the correct side of an adoption, and the equation holds on both sides', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $inventoryId = inventorySeed($product, 10);
    $before = inventoryOrder($store, [[$product, 1]]);
    $after = inventoryOrder($store, [[$product, 2]]);

    sale()->reserve($before);
    sale()->reserve($after);
    sale()->commit($before);

    $baseline = app(InventoryControlService::class)->adopt($product, inventoryActor($product), 'n');

    sale()->commit($after);

    $stamps = InventoryMovement::query()->where('reason', 'sale')->orderBy('id')->pluck('after_baseline')->all();
    $evidenced = (int) DB::table('inventory_movements')->where('inventory_id', $inventoryId)->where('after_baseline', true)->sum('quantity_delta');

    expect($stamps)->toBe([false, true])
        ->and($baseline->on_hand_at_baseline)->toBe(9)
        ->and($baseline->reserved_at_baseline)->toBe(2)
        ->and(inventoryState($product)['on_hand'])->toBe($baseline->on_hand_at_baseline + $evidenced);
});

it('reads the stamp from the Inventory row it holds, through a locking statement, right before the sale insert', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 5);
    $order = inventoryOrder($store, [[$product, 1]]);
    sale()->reserve($order);

    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql) {
        $sql[] = inventorySql($q->sql);
    });

    try {
        sale()->commit($order);
    } finally {
        Event::forget(QueryExecuted::class);
    }

    $insert = array_key_first(array_filter($sql, fn ($s) => str_starts_with($s, 'insert into "inventory_movements"')));

    expect($sql[$insert - 1])->toContain('select "is_baselined" from "inventories"');
});

it('rolls the whole commit back if the sale evidence cannot be written: CAS, counters and every line', function () {
    $store = Store::factory()->create();
    [$a] = inventoryGenesis($store, 5, 'A');
    [$b] = inventoryGenesis($store, 5, 'B');
    $order = inventoryOrder($store, [[$a, 1], [$b, 1]]);
    sale()->reserve($order);

    $inserts = 0;

    DB::listen(function (QueryExecuted $q) use (&$inserts) {
        if (str_starts_with(inventorySql($q->sql), 'insert into "inventory_movements"') && ++$inserts === 2) {
            throw new RuntimeException('simulated crash after the first sale movement');
        }
    });

    try {
        expect(fn () => sale()->commit($order))->toThrow(RuntimeException::class, 'simulated crash');
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect(saleMovements())->toBe(0)
        ->and(inventoryState($a))->toBe(['on_hand' => 5, 'reserved' => 1, 'available' => 4])
        ->and(inventoryState($b))->toBe(['on_hand' => 5, 'reserved' => 1, 'available' => 4])
        ->and(DB::table('inventory_reservations')->where('status', 'reserved')->count())->toBe(2);

    // Rollback was the compensation: the retry applies once.
    expect(sale()->commit($order))->toBe(2)
        ->and(saleMovements())->toBe(2);
});

it('treats a pre-existing sale identity as corruption, never as a replay: it fails closed and changes nothing', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 5);
    $order = inventoryOrder($store, [[$product, 1]]);
    sale()->reserve($order);
    sale()->commit($order);

    // A status raw-flipped back: the CAS can be won again, the sale already exists.
    DB::table('inventory_reservations')->update(['status' => 'reserved', 'committed_at' => null]);
    DB::table('inventories')->where('product_id', $product->id)->update(['on_hand_quantity' => 5, 'reserved_quantity' => 1]);

    expect(fn () => sale()->commit($order))->toThrow(InventoryIntegrityException::class, 'already has sale evidence')
        ->and(saleMovements())->toBe(1)
        ->and(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 1, 'available' => 4])
        ->and(DB::table('inventory_reservations')->value('status'))->toBe('reserved');
});

it('keeps a drifted counter a refusal: the commit rolls back with no movement', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    sale()->reserve($order);

    DB::table('inventories')->where('product_id', $product->id)->update(['reserved_quantity' => 1]);

    expect(fn () => sale()->commit($order))->toThrow(InventoryIntegrityException::class)
        ->and(saleMovements())->toBe(0)
        ->and(DB::table('inventory_reservations')->value('status'))->toBe('reserved');
});

it('still refuses a released reservation: no sale movement for a refused commit', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 5);
    $order = inventoryOrder($store, [[$product, 2]]);
    sale()->reserve($order);
    sale()->release($order);

    expect(fn () => sale()->commit($order))->toThrow(InvalidReservationException::class)
        ->and(saleMovements())->toBe(0);
});
