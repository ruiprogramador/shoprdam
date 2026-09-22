<?php

use App\Domain\Inventory\Enums\InventoryReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Services\InventoryIntegrityChecker;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * INVENTORY-20 / design document §9 — the read-only drift detector, plus the
 * conservation equation checked against a long deterministic sequence of
 * canonical operations.
 */
function icCodes(array $findings): array
{
    return array_values(array_unique(array_column($findings, 'code')));
}

function icAudit(): array
{
    return (new InventoryIntegrityChecker(chunkSize: 2))->audit();
}

it('reports nothing for a consistent inventory in every reservation state', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 10);
    $service = app(InventoryReservationService::class);

    $reserved = inventoryOrder($store, [[$product, 1]]);
    $committed = inventoryOrder($store, [[$product, 2]]);
    $released = inventoryOrder($store, [[$product, 3]]);

    foreach ([$reserved, $committed, $released] as $order) {
        $service->reserve($order);
    }

    $service->commit($committed);
    $service->release($released);

    expect(icAudit())->toBe([])
        ->and(inventoryState($product))->toBe(['on_hand' => 8, 'reserved' => 1, 'available' => 7]);
});

it('detects a counter that disagrees with its reservations (raw stock mutation)', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 10);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 4]]));

    DB::table('inventories')->update(['reserved_quantity' => 1]);

    expect(icCodes(icAudit()))->toBe([InventoryIntegrityChecker::COUNTER_DISAGREES_WITH_RESERVATIONS]);
});

it('detects raw deletion of a reservation row (the hold vanished, the counter still counts it)', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 10);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 4]]));

    // The database does not forbid this delete: nothing references a reservation.
    DB::table('inventory_reservations')->delete();

    expect(icCodes(icAudit()))->toBe([InventoryIntegrityChecker::COUNTER_DISAGREES_WITH_RESERVATIONS]);
});

it('detects a terminal reservation still counted as live, and a live one no longer counted', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 10);
    $service = app(InventoryReservationService::class);
    $order = inventoryOrder($store, [[$product, 4]]);
    $service->reserve($order);
    $service->release($order);

    // Counter re-inflated behind the service's back.
    DB::table('inventories')->update(['reserved_quantity' => 4]);

    expect(icCodes(icAudit()))->toContain(InventoryIntegrityChecker::COUNTER_DISAGREES_WITH_RESERVATIONS);
});

it('detects a reservation that disagrees with its OrderItem or its Inventory', function () {
    $store = Store::factory()->create();
    $a = inventoryProduct($store, '10.00', 'A');
    $b = inventoryProduct($store, '10.00', 'B');
    inventorySeed($a, 10);
    $inventoryB = inventorySeed($b, 10);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$a, 2]]));

    DB::table('inventory_reservations')->update(['quantity' => 1]);
    expect(icCodes(icAudit()))->toContain(InventoryIntegrityChecker::QUANTITY_MISMATCH);

    DB::table('inventory_reservations')->update(['quantity' => 2, 'inventory_id' => $inventoryB]);
    expect(icCodes(icAudit()))->toContain(InventoryIntegrityChecker::INVENTORY_PRODUCT_MISMATCH);
});

it('never repairs: an audit leaves every row exactly as it found it', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 10);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 4]]));
    DB::table('inventories')->update(['reserved_quantity' => 1]);

    $before = [DB::table('inventories')->get()->all(), DB::table('inventory_reservations')->get()->all()];

    expect(icAudit())->not->toBe([]);

    expect([DB::table('inventories')->get()->all(), DB::table('inventory_reservations')->get()->all()])->toEqual($before);
});

it('walks the tables in bounded chunks — a chunk size smaller than the table changes nothing', function () {
    $store = Store::factory()->create();
    $service = app(InventoryReservationService::class);

    foreach (range(1, 5) as $n) {
        $product = inventoryProduct($store, '10.00', "P{$n}");
        inventorySeed($product, 3);
        $service->reserve(inventoryOrder($store, [[$product, 1]]));
    }

    expect((new InventoryIntegrityChecker(chunkSize: 1))->audit())->toBe([]);

    DB::table('inventories')->where('product_id', '>', 0)->limit(1)->update(['reserved_quantity' => 0]);

    expect(icCodes((new InventoryIntegrityChecker(chunkSize: 1))->audit()))->toBe([InventoryIntegrityChecker::COUNTER_DISAGREES_WITH_RESERVATIONS]);
});

it('CONSERVATION: over a long deterministic sequence of reserve/commit/release, available is never negative and every equation holds', function () {
    mt_srand(20260923);

    $store = Store::factory()->create();
    $service = app(InventoryReservationService::class);
    $products = [];
    $initial = [];

    foreach (range(1, 3) as $n) {
        $products[$n] = inventoryProduct($store, '10.00', "P{$n}");
        $initial[$products[$n]->id] = 20;
        inventorySeed($products[$n], 20);
    }

    /** @var list<array{order: Order, state: string}> $orders */
    $orders = [];

    foreach (range(1, 120) as $step) {
        $action = mt_rand(1, 3);

        if ($action === 1 || $orders === []) {
            $lines = [];
            foreach ((array) array_rand($products, mt_rand(1, 3)) as $key) {
                $lines[] = [$products[$key], mt_rand(1, 6)];
            }

            $order = inventoryOrder($store, $lines);

            try {
                $service->reserve($order);
                $orders[] = ['order' => $order, 'state' => 'reserved'];
            } catch (InsufficientStockException) {
                // refused: no effect, checked by the invariants below
            }
        } else {
            $key = array_rand($orders);

            if ($orders[$key]['state'] === 'reserved') {
                $action === 2 ? $service->commit($orders[$key]['order']) : $service->release($orders[$key]['order']);
                $orders[$key]['state'] = $action === 2 ? 'committed' : 'released';
            }
        }

        foreach ($products as $product) {
            $state = inventoryState($product);
            $inventoryId = DB::table('inventories')->where('product_id', $product->id)->value('id');

            $sum = fn (string $status) => (int) DB::table('inventory_reservations')
                ->where('inventory_id', $inventoryId)->where('status', $status)->sum('quantity');

            expect($state['available'])->toBeGreaterThanOrEqual(0)
                // reserved is exactly the live holds
                ->and($state['reserved'])->toBe($sum(InventoryReservationStatus::Reserved->value))
                // physical stock fell by exactly what was committed, nothing else
                ->and($state['on_hand'])->toBe($initial[$product->id] - $sum(InventoryReservationStatus::Committed->value));
        }
    }

    expect(icAudit())->toBe([]);
});
