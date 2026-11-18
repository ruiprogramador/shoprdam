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
 * The evidence half of InventoryIntegrityChecker (docs/inventory/INVENTORY-CONTROL.md
 * §15): the reconciliation equation and every piece of evidence against what it
 * claims to evidence. Each detection attacks with raw SQL, because that is all
 * that could produce these states. The checker is read-only: it detects, and
 * repairs nothing.
 */
function evAudit(): array
{
    return (new InventoryIntegrityChecker(chunkSize: 2))->audit();
}

/** @return list<string> */
function evCodes(): array
{
    return array_values(array_unique(array_column(evAudit(), 'code')));
}

function evRawMovement(array $overrides): int
{
    return DB::table('inventory_movements')->insertGetId([
        'quantity_delta' => 1, 'reason' => 'receipt', 'idempotency_key' => 'client:raw-'.uniqid(), 'actor_type' => 'store_owner', 'actor_user_id' => 1,
        'note' => 'raw', 'after_baseline' => true, 'created_at' => now(),
        ...$overrides,
    ]);
}

function evControl(): InventoryControlService
{
    return app(InventoryControlService::class);
}

function evCommitted(Store $store, $product, int $quantity): int
{
    $service = app(InventoryReservationService::class);
    $order = inventoryOrder($store, [[$product, $quantity]]);
    $service->reserve($order);
    $service->commit($order);

    return (int) DB::table('inventory_reservations')->where('order_item_id', $order->items->first()->id)->value('id');
}

it('reports nothing for a fully exercised Inventory: every operation keeps the equation exact', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 20);
    $actor = inventoryActor($product);
    $counts = app(InventoryCountService::class);

    $receipt = evControl()->receive($product, 5, 'r', $actor, 'n')->movement;
    $writeOff = evControl()->writeOff($product, 2, 'w', $actor, 'n')->movement;
    evControl()->correct($product, $receipt->id, $actor, 'n');
    evControl()->correct($product, $writeOff->id, $actor, 'n');
    evCommitted($store, $product, 3);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 10]]));

    $counts->submitCount($product, 17, 2, 'short', $actor);
    $counts->submitCount($product, 99, 2, 'stale', $actor);
    $counts->submitCount($product, 17, 16, 'count', $actor);

    expect(evAudit())->toBe([])
        ->and(inventoryState($product)['on_hand'])->toBe(16);
});

it('reconciles an adopted Inventory from its baseline, never from zero', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 50);

    evCommitted($store, $product, 5);
    evControl()->adopt($product, inventoryActor($product), 'n');
    evCommitted($store, $product, 2);
    evControl()->receive($product, 1, 'r', inventoryActor($product), 'n');

    expect(evAudit())->toBe([])
        ->and(inventoryState($product)['on_hand'])->toBe(44);
});

it('detects a raw on_hand edit on a baselined Inventory', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);

    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 6]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::ON_HAND_DISAGREES_WITH_EVIDENCE])
        ->and(evAudit()[0]['detail'])->toBe('on_hand_quantity is 6 but its baseline and movements give 5.');
});

it('reports the equation from fresh reads: a transition between its two reads is not drift (simulation)', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    $armed = true;

    // on_hand was read as 5; then a receipt lands before the movements are summed.
    DB::listen(function (QueryExecuted $q) use (&$armed, $product) {
        if ($armed && str_contains(inventorySql($q->sql), 'from "inventory_baselines"')) {
            $armed = false;
            evControl()->receive($product, 1, 'in-between', inventoryActor($product), 'n');
        }
    });

    try {
        $findings = (new InventoryIntegrityChecker)->audit();
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($armed)->toBeFalse()
        ->and($findings)->toBe([])
        ->and(DB::table('inventories')->where('id', $id)->value('on_hand_quantity'))->toEqual(6);
});

it('detects a raw deleted movement, and a raw inserted one', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    $receipt = evControl()->receive($product, 1, 'r', inventoryActor($product), 'n')->movement;

    DB::table('inventory_movements')->where('id', $receipt->id)->delete();

    expect(evCodes())->toBe([InventoryIntegrityChecker::ON_HAND_DISAGREES_WITH_EVIDENCE]);

    evRawMovement(['inventory_id' => $id]);
    evRawMovement(['inventory_id' => $id, 'quantity_delta' => 1]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::ON_HAND_DISAGREES_WITH_EVIDENCE]);
});

it('states what it cannot see: a coordinated raw movement plus a matching on_hand edit keeps the equation true', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);

    evRawMovement(['inventory_id' => $id, 'quantity_delta' => 3]);
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 8]);

    expect(evAudit())->toBe([]);
});

it('does not evaluate the equation for an unbaselined Inventory, and never reports it as consistent', function () {
    $product = inventoryProduct(Store::factory()->create());
    $id = inventorySeed($product, 5);

    expect(evAudit())->toBe([]);

    $report = (new InventoryIntegrityChecker)->evidenceReport();

    expect($report['unbaselined'])->toHaveCount(1)
        ->and($report['unbaselined'][0]['code'])->toBe(InventoryIntegrityChecker::INVENTORY_UNBASELINED)
        ->and($report['unbaselined'][0]['inventory_id'])->toBe($id);
});

it('reports an open discrepancy as attention, not as corruption, until a re-count supersedes it', function () {
    $store = Store::factory()->create();
    [$product] = inventoryGenesis($store, 10);
    $actor = inventoryActor($product);
    $counts = app(InventoryCountService::class);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 7]]));

    $counts->submitCount($product, 10, 5, 'short', $actor);

    expect(evAudit())->toBe([])
        ->and((new InventoryIntegrityChecker)->evidenceReport()['open_discrepancies'])->toHaveCount(1);

    $counts->submitCount($product, 10, 9, 'found', $actor);

    expect((new InventoryIntegrityChecker)->evidenceReport()['open_discrepancies'])->toBe([]);
});

it('detects a flag and a baseline row that disagree, both ways', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create());

    DB::table('inventories')->where('id', $id)->update(['is_baselined' => false]);

    expect(evCodes())->toContain(InventoryIntegrityChecker::BASELINE_FLAG_MISMATCH);

    $legacy = inventorySeed(inventoryProduct(Store::factory()->create()), 1);
    DB::table('inventories')->where('id', $legacy)->update(['is_baselined' => true]);

    expect(collect(evAudit())->where('inventory_id', $legacy)->pluck('code')->all())->toContain(InventoryIntegrityChecker::BASELINE_FLAG_MISMATCH);
});

it('detects a sale whose reservation is no longer committed', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 5);
    $reservationId = evCommitted($store, $product, 1);

    DB::table('inventory_reservations')->where('id', $reservationId)->update(['status' => 'reserved', 'committed_at' => null]);
    DB::table('inventories')->where('id', $id)->update(['reserved_quantity' => 1]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::SALE_RESERVATION_NOT_COMMITTED]);
});

it('detects a sale that disagrees with its reservation', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 5);
    evCommitted($store, $product, 2);

    DB::table('inventory_movements')->where('reason', 'sale')->update(['quantity_delta' => -3]);
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 2]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::SALE_MISMATCH]);
});

it('detects duplicate sale evidence forged under another key', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 5);
    $reservationId = evCommitted($store, $product, 1);

    evRawMovement([
        'inventory_id' => $id, 'reason' => 'sale', 'quantity_delta' => -1, 'reservation_id' => $reservationId, 'actor_type' => 'system', 'actor_user_id' => null,
        'note' => null, 'after_baseline' => true,
    ]);
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 3]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::DUPLICATE_SALE_EVIDENCE]);
});

it('detects a committed reservation of a genesis Inventory with no sale evidence, and not one of an adopted Inventory', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 5);
    evCommitted($store, $product, 1);

    DB::table('inventory_movements')->where('reason', 'sale')->delete();
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 5]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::COMMITTED_RESERVATION_WITHOUT_SALE]);

    $legacy = inventoryProduct($store, '10.00', 'Legacy');
    $legacyId = inventorySeed($legacy, 5);
    evCommitted($store, $legacy, 1);
    DB::table('inventory_movements')->where('inventory_id', $legacyId)->delete();
    evControl()->adopt($legacy, inventoryActor($legacy), 'n');

    expect(collect(evAudit())->where('inventory_id', $legacyId)->all())->toBe([]);
});

it('detects a stamp on the wrong side of a baseline', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 5);
    evCommitted($store, $product, 1);

    // A genesis Inventory has no "before": both the stamp and the equation report it.
    DB::table('inventory_movements')->where('reason', 'sale')->update(['after_baseline' => false]);

    expect(evCodes())->toEqualCanonicalizing([
        InventoryIntegrityChecker::ON_HAND_DISAGREES_WITH_EVIDENCE,
        InventoryIntegrityChecker::MOVEMENT_BASELINE_STAMP_INVALID,
    ]);

    DB::table('inventory_movements')->where('reason', 'sale')->update(['after_baseline' => true]);

    $legacy = inventoryProduct($store, '10.00', 'Legacy');
    $legacyId = inventorySeed($legacy, 5);
    evCommitted($store, $legacy, 1);
    DB::table('inventory_movements')->where('inventory_id', $legacyId)->update(['after_baseline' => true]);

    expect(collect(evAudit())->where('inventory_id', $legacyId)->pluck('code')->all())->toBe([InventoryIntegrityChecker::MOVEMENT_BASELINE_STAMP_INVALID]);
});

it('detects a correction that is not the exact reversal of a correctable movement, and a second correction', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    $actor = inventoryActor($product);
    $receipt = evControl()->receive($product, 2, 'r', $actor, 'n')->movement;
    $correction = evControl()->correct($product, $receipt->id, $actor, 'n')->movement;

    DB::table('inventory_movements')->where('id', $correction->id)->update(['quantity_delta' => -1]);
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 6]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::CORRECTION_INVALID]);

    DB::table('inventory_movements')->where('id', $correction->id)->update(['quantity_delta' => -2]);
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 3]);
    evRawMovement(['inventory_id' => $id, 'reason' => 'correction', 'quantity_delta' => -2, 'corrects_movement_id' => $receipt->id]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::CORRECTION_INVALID]);
});

it('detects an adjustment that disagrees with its count, and an applied variance with no adjustment', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 10);
    $result = app(InventoryCountService::class)->submitCount($product, 10, 8, 'count', inventoryActor($product));

    DB::table('inventory_movements')->where('id', $result->adjustment->id)->update(['quantity_delta' => -1]);
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 9]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::COUNT_ADJUSTMENT_INVALID]);

    DB::table('inventory_movements')->where('id', $result->adjustment->id)->delete();
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 10]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::COUNT_ADJUSTMENT_INVALID]);
});

it('detects a discrepancy resolved by anything but an applied count of its Inventory', function () {
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, 10);
    $actor = inventoryActor($product);
    $counts = app(InventoryCountService::class);
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 7]]));

    $open = $counts->submitCount($product, 10, 5, 'short', $actor)->count;
    $stale = $counts->submitCount($product, 99, 5, 'stale', $actor)->count;

    DB::table('inventory_counts')->where('id', $open->id)->update(['status' => 'discrepancy_resolved', 'resolved_by_count_id' => $stale->id, 'resolved_at' => now()]);

    expect(evCodes())->toBe([InventoryIntegrityChecker::COUNT_RESOLUTION_INVALID]);
});

it('never repairs: an audit and a report leave every row exactly as they found them', function () {
    [$product, $id] = inventoryGenesis(Store::factory()->create(), 5);
    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 7, 'is_baselined' => false]);

    $snapshot = fn () => [
        DB::table('inventories')->get()->all(),
        DB::table('inventory_movements')->get()->all(),
        DB::table('inventory_baselines')->get()->all(),
        DB::table('inventory_counts')->get()->all(),
    ];

    $before = $snapshot();

    $checker = new InventoryIntegrityChecker;
    $checker->audit();
    $checker->evidenceReport();

    expect($snapshot())->toEqual($before);
});

it('issues only reads', function () {
    [$product] = inventoryGenesis(Store::factory()->create(), 5);
    evControl()->receive($product, 1, 'r', inventoryActor($product), 'n');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $checker = new InventoryIntegrityChecker;
    $checker->audit();
    $checker->evidenceReport();

    foreach (DB::getQueryLog() as $query) {
        expect($query['query'])->toStartWith('select');
    }
});

it('walks in bounded chunks: a chunk smaller than every table changes no finding', function () {
    $store = Store::factory()->create();

    foreach (range(1, 5) as $i) {
        [$product, $id] = inventoryGenesis($store, 5, "P{$i}");
        evCommitted($store, $product, 1);
    }

    DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 1]);

    expect((new InventoryIntegrityChecker(chunkSize: 1))->audit())->toEqual((new InventoryIntegrityChecker(chunkSize: 100))->audit())
        ->and((new InventoryIntegrityChecker(chunkSize: 1))->audit())->toHaveCount(1);
});
