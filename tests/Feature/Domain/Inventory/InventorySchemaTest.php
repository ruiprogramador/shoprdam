<?php

use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ACTUAL migrated schema, read back and then attacked with raw SQL (which
 * bypasses every model guard and the service on purpose): what the database
 * itself refuses. SQLite only — the CHECK constraints these triggers stand in
 * for on MySQL/MariaDB/PostgreSQL are not executed by this repository's tests
 * (docs/inventory/INVENTORY-RESERVATIONS.md §11).
 */
function isReservationRow(int $inventoryId, int $orderItemId, array $overrides = []): array
{
    return [
        'inventory_id' => $inventoryId, 'order_item_id' => $orderItemId, 'quantity' => 1, 'status' => 'reserved',
        'committed_at' => null, 'released_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ...$overrides,
    ];
}

/** @return array{0: int, 1: int, 2: int} inventory id, order item id, second order item id */
function isFixture(): array
{
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    $inventoryId = inventorySeed($product, 5);
    $order = inventoryOrder($store, [[$product, 1]]);
    $other = inventoryOrder($store, [[$product, 1]]);

    return [$inventoryId, $order->items->first()->id, $other->items->first()->id];
}

it('has exactly the approved minimal columns — no warehouse, sku, variant, supplier, lot, expiry or backorder', function () {
    expect(Schema::getColumnListing('inventories'))->toBe([
        'id', 'product_id', 'on_hand_quantity', 'reserved_quantity', 'created_at', 'updated_at',
    ])->and(Schema::getColumnListing('inventory_reservations'))->toBe([
        'id', 'inventory_id', 'order_item_id', 'quantity', 'status', 'committed_at', 'released_at', 'created_at', 'updated_at',
    ]);

    // available is derived, never stored.
    expect(Schema::hasColumn('inventories', 'available_quantity'))->toBeFalse();
});

it('stores no history-erasing columns: neither table is soft-deletable', function () {
    expect(Schema::hasColumn('inventories', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('inventory_reservations', 'deleted_at'))->toBeFalse();
});

it('starts empty: no stock and no reservation is ever backfilled from Order history', function () {
    expect(DB::table('inventories')->count())->toBe(0)
        ->and(DB::table('inventory_reservations')->count())->toBe(0);
});

it('allows one Inventory row per Product (unique product_id)', function () {
    $product = inventoryProduct(Store::factory()->create());
    inventorySeed($product, 1);

    expect(fn () => inventorySeed($product, 2))->toThrow(QueryException::class);
});

it('INVENTORY-01: the database refuses negative or over-reserved counters, however they are written', function () {
    $product = inventoryProduct(Store::factory()->create());
    $id = inventorySeed($product, 5, 2);

    $refused = [
        'negative on_hand (insert)' => fn () => inventorySeed(inventoryProduct(Store::factory()->create()), -1),
        'negative reserved (insert)' => fn () => inventorySeed(inventoryProduct(Store::factory()->create()), 5, -1),
        'reserved above on_hand (insert)' => fn () => inventorySeed(inventoryProduct(Store::factory()->create()), 5, 6),
        'reserved above on_hand (update)' => fn () => DB::table('inventories')->where('id', $id)->update(['reserved_quantity' => 6]),
        'on_hand below reserved (update)' => fn () => DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 1]),
        'negative reserved (update)' => fn () => DB::table('inventories')->where('id', $id)->update(['reserved_quantity' => -1]),
        'non-integer counter (update)' => fn () => DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 'lots']),
        'null counter' => fn () => DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => null]),
    ];

    foreach ($refused as $label => $attempt) {
        expect($attempt)->toThrow(QueryException::class);
    }

    expect(inventoryState($product))->toBe(['on_hand' => 5, 'reserved' => 2, 'available' => 3]);

    // The boundary itself is allowed: reserved == on_hand.
    DB::table('inventories')->where('id', $id)->update(['reserved_quantity' => 5]);

    expect(inventoryState($product)['available'])->toBe(0);
});

it('constrains reservations: unique order item, positive quantity, a known status carrying exactly its own evidence timestamp', function () {
    [$inventoryId, $itemA, $itemB] = isFixture();

    DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemA));

    $refused = [
        'second reservation for the same OrderItem' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemA)),
        'zero quantity' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['quantity' => 0])),
        'negative quantity' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['quantity' => -1])),
        'unknown status' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['status' => 'expired'])),
        'reserved with a committed_at' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['committed_at' => now()])),
        'committed without committed_at' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['status' => 'committed'])),
        'released without released_at' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['status' => 'released'])),
        'committed AND released' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['status' => 'committed', 'committed_at' => now(), 'released_at' => now()])),
        'a half-written terminal transition (status only)' => fn () => DB::table('inventory_reservations')->where('order_item_id', $itemA)->update(['status' => 'committed']),
        'non-integer quantity' => fn () => DB::table('inventory_reservations')->where('order_item_id', $itemA)->update(['quantity' => 'many']),
        'unknown inventory' => fn () => DB::table('inventory_reservations')->insert(isReservationRow(999999, $itemB)),
        'unknown order item' => fn () => DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, 999999)),
    ];

    foreach ($refused as $label => $attempt) {
        expect($attempt)->toThrow(QueryException::class);
    }

    // Complete, coherent terminal rows are accepted.
    DB::table('inventory_reservations')->insert(isReservationRow($inventoryId, $itemB, ['status' => 'released', 'released_at' => now()]));

    expect(DB::table('inventory_reservations')->count())->toBe(2);
});
