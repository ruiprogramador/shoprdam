<?php

use App\Domain\Inventory\DTOs\StoreOwnerActor;
use App\Domain\Inventory\Enums\InventoryCountStatus;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use App\Domain\Inventory\Exceptions\InvalidInventoryOperationException;
use App\Domain\Inventory\Exceptions\InventoryIdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InventoryNotBaselinedException;
use App\Domain\Inventory\Models\InventoryCount;
use App\Domain\Inventory\Services\InventoryControlService;
use App\Domain\Inventory\Services\InventoryCountService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Models\Store;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Physical counts (docs/inventory/INVENTORY-CONTROL.md §6.7, §7, §16), on the
 * default SQLite suite: algorithm and state machine only. Implemented and proven,
 * NOT activated: nothing in production may call this (STOCK-22).
 *
 * Every scenario keeps apart what was observed from the authoritative `on_hand`
 * at the moment the observation is applied.
 */
function cnt(): InventoryCountService
{
    return app(InventoryCountService::class);
}

function cntState(int $inventoryId): array
{
    $row = DB::table('inventories')->find($inventoryId);

    return ['on_hand' => (int) $row->on_hand_quantity, 'reserved' => (int) $row->reserved_quantity];
}

function cntMovements(int $inventoryId, string $reason = 'count_adjustment'): int
{
    return DB::table('inventory_movements')->where('inventory_id', $inventoryId)->where('reason', $reason)->count();
}

/** @return array{0: mixed, 1: int, 2: Store} a product, its inventory id and its store */
function cntFixture(int $stock, int $reserved = 0): array
{
    $store = Store::factory()->create();
    [$product, $id] = inventoryGenesis($store, $stock);

    if ($reserved > 0) {
        app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, $reserved]]));
    }

    return [$product, $id, $store];
}

// ---------------------------------------------------------------------
// applied
// ---------------------------------------------------------------------

it('applies a zero-variance count: stored as applied, no movement, nothing changes', function () {
    [$product, $id] = cntFixture(10);

    $result = cnt()->submitCount($product, 10, 10, 'count-1', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Applied)
        ->and($result->adjustment)->toBeNull()
        ->and($result->replayed)->toBeFalse()
        ->and(cntMovements($id))->toBe(0)
        ->and(cntState($id)['on_hand'])->toBe(10);
});

it('applies a negative variance: exactly one count_adjustment, correlated to the count', function () {
    [$product, $id] = cntFixture(10);
    $actor = inventoryActor($product);

    $result = cnt()->submitCount($product, 10, 7, 'count-1', $actor, 'shelf recount');

    expect($result->count->status)->toBe(InventoryCountStatus::Applied)
        ->and($result->count->observed_quantity)->toBe(7)
        ->and($result->count->expected_on_hand)->toBe(10)
        ->and($result->count->on_hand_at_submission)->toBe(10)
        ->and($result->count->reserved_at_submission)->toBe(0)
        ->and($result->count->actor_user_id)->toBe($actor->userId)
        ->and($result->count->note)->toBe('shelf recount')
        ->and($result->adjustment->reason)->toBe(InventoryMovementReason::CountAdjustment)
        ->and($result->adjustment->quantity_delta)->toBe(-3)
        ->and((int) $result->adjustment->count_id)->toBe($result->count->id)
        ->and($result->adjustment->idempotency_key)->toBe('count:'.$result->count->id)
        ->and($result->adjustment->after_baseline)->toBeTrue()
        ->and($result->adjustment->actor_user_id)->toBe($actor->userId)
        ->and(cntMovements($id))->toBe(1)
        ->and(cntState($id)['on_hand'])->toBe(7);
});

it('applies a positive variance', function () {
    [$product, $id] = cntFixture(10);

    $result = cnt()->submitCount($product, 10, 12, 'count-1', inventoryActor($product));

    expect($result->adjustment->quantity_delta)->toBe(2)
        ->and(cntState($id)['on_hand'])->toBe(12);
});

it('takes a new Inventory from zero: an initial stocktake is a count with expected 0, evidenced like any other', function () {
    [$product, $id] = cntFixture(0);

    $result = cnt()->submitCount($product, 0, 25, 'initial', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Applied)
        ->and($result->adjustment->quantity_delta)->toBe(25)
        ->and(cntState($id)['on_hand'])->toBe(25);
});

it('applies down to exactly the reserved quantity, and never past it', function () {
    [$product, $id] = cntFixture(10, 7);

    $result = cnt()->submitCount($product, 10, 7, 'count-1', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Applied)
        ->and(cntState($id))->toBe(['on_hand' => 7, 'reserved' => 7]);
});

it('takes the applied count through the conditional compare-and-set, with the snapshot in its predicate', function () {
    [$product] = cntFixture(10, 2);

    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql) {
        $sql[] = ['sql' => inventorySql($q->sql), 'bindings' => $q->bindings];
    });

    try {
        cnt()->submitCount($product, 10, 8, 'count-1', inventoryActor($product));
    } finally {
        Event::forget(QueryExecuted::class);
    }

    $update = collect($sql)->first(fn ($q) => str_starts_with($q['sql'], 'update "inventories" set "on_hand_quantity"'));

    expect($update['sql'])->toContain('where "id" = ? and "on_hand_quantity" = ? and "reserved_quantity" <= ?')
        ->and(array_slice($update['bindings'], -2))->toBe([10, 8]);
});

// ---------------------------------------------------------------------
// stale: §7.1 cases A-F, with t0: on_hand = 10, counted 9
// ---------------------------------------------------------------------

it('case A: a sale after the count makes it stale: the sale is never undone', function () {
    [$product, $id, $store] = cntFixture(10);
    $reservations = app(InventoryReservationService::class);
    $order = inventoryOrder($store, [[$product, 1]]);
    $reservations->reserve($order);
    $reservations->commit($order);

    $result = cnt()->submitCount($product, 10, 9, 'stale-count', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Stale)
        ->and($result->count->observed_quantity)->toBe(9)
        ->and($result->count->on_hand_at_submission)->toBe(9)
        ->and($result->adjustment)->toBeNull()
        ->and(cntMovements($id))->toBe(0)
        ->and(cntState($id)['on_hand'])->toBe(9);
});

it('case B: a receipt after the count makes it stale: the receipt is never erased', function () {
    [$product, $id] = cntFixture(10);
    app(InventoryControlService::class)->receive($product, 5, 'delivery', inventoryActor($product), 'n');

    $result = cnt()->submitCount($product, 10, 9, 'stale-count', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Stale)
        ->and(cntState($id)['on_hand'])->toBe(15);
});

it('case C: a write-off after the count makes it stale: no phantom units appear', function () {
    [$product, $id] = cntFixture(10);
    app(InventoryControlService::class)->writeOff($product, 2, 'broken', inventoryActor($product), 'n');

    $result = cnt()->submitCount($product, 10, 9, 'stale-count', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Stale)
        ->and(cntState($id)['on_hand'])->toBe(8);
});

it('case D: a reservation after the count does not make it stale: reservations move no goods', function () {
    [$product, $id] = cntFixture(10, 3);

    $result = cnt()->submitCount($product, 10, 9, 'count', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Applied)
        ->and(cntState($id))->toBe(['on_hand' => 9, 'reserved' => 3]);
});

it('case E: A-B-A is value-correct: a sale then a receipt of the same size leaves the snapshot valid', function () {
    [$product, $id, $store] = cntFixture(10);
    $reservations = app(InventoryReservationService::class);
    $order = inventoryOrder($store, [[$product, 1]]);
    $reservations->reserve($order);
    $reservations->commit($order);
    app(InventoryControlService::class)->receive($product, 1, 'back', inventoryActor($product), 'n');

    $result = cnt()->submitCount($product, 10, 9, 'count', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Applied)
        ->and(cntState($id)['on_hand'])->toBe(9);
});

it('case F: a second count of a snapshot another count already moved is stale', function () {
    [$product, $id] = cntFixture(10);
    $actor = inventoryActor($product);

    cnt()->submitCount($product, 10, 8, 'first', $actor);
    $second = cnt()->submitCount($product, 10, 7, 'second', $actor);

    expect($second->count->status)->toBe(InventoryCountStatus::Stale)
        ->and(cntState($id)['on_hand'])->toBe(8)
        ->and(cntMovements($id))->toBe(1);
});

it('checks staleness first: against a moved on_hand, an observation below reserved is stale, not a discrepancy', function () {
    [$product, $id] = cntFixture(10, 7);
    app(InventoryControlService::class)->receive($product, 5, 'delivery', inventoryActor($product), 'n');

    $result = cnt()->submitCount($product, 10, 5, 'count', inventoryActor($product));

    expect($result->count->status)->toBe(InventoryCountStatus::Stale)
        ->and(cntState($id))->toBe(['on_hand' => 15, 'reserved' => 7]);
});

it('decides from the locked row, not from anything read before it (simulation: a competitor moves on_hand right before the lock)', function () {
    [$product, $id] = cntFixture(10);
    $actor = inventoryActor($product);
    $armed = true;

    DB::listen(function (QueryExecuted $q) use (&$armed, $id) {
        if ($armed && str_contains(inventorySql($q->sql), 'from "inventory_counts"') && str_contains($q->sql, 'idempotency_key')) {
            $armed = false;
            DB::table('inventories')->where('id', $id)->update(['on_hand_quantity' => 9]);
        }
    });

    try {
        $result = cnt()->submitCount($product, 10, 7, 'count', $actor);
    } finally {
        Event::forget(QueryExecuted::class);
    }

    expect($result->count->status)->toBe(InventoryCountStatus::Stale)
        ->and($result->count->on_hand_at_submission)->toBe(9)
        ->and(cntState($id)['on_hand'])->toBe(9);
});

// ---------------------------------------------------------------------
// discrepancy: the 10/7/5 case
// ---------------------------------------------------------------------

it('keeps a count below reserved as evidence: on_hand stays, no movement, no reservation is touched', function () {
    [$product, $id] = cntFixture(10, 7);

    $result = cnt()->submitCount($product, 10, 5, 'short', inventoryActor($product), 'seven promised, five found');

    expect($result->count->status)->toBe(InventoryCountStatus::DiscrepancyOpen)
        ->and($result->count->observed_quantity)->toBe(5)
        ->and($result->count->on_hand_at_submission)->toBe(10)
        ->and($result->count->reserved_at_submission)->toBe(7)
        ->and($result->adjustment)->toBeNull()
        ->and(cntState($id))->toBe(['on_hand' => 10, 'reserved' => 7])
        ->and(DB::table('inventory_movements')->where('inventory_id', $id)->where('reason', '<>', 'receipt')->count())->toBe(0)
        ->and(DB::table('inventory_reservations')->where('status', 'reserved')->count())->toBe(1)
        ->and(DB::table('inventory_reservations')->where('status', 'released')->count())->toBe(0);
});

it('does not touch the reserve guard: an open discrepancy blocks nothing until O-1 is decided, which is why counting is not activated', function () {
    [$product, $id, $store] = cntFixture(10, 7);

    cnt()->submitCount($product, 10, 5, 'short', inventoryActor($product));

    // Documents the risk rather than endorsing it: the 3 system-available units
    // can still be reserved although the observation says they may not exist.
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$product, 3]]));

    expect(cntState($id))->toBe(['on_hand' => 10, 'reserved' => 10]);
});

it('never converts a shortage into a write-off: a discrepancy produces no write_off', function () {
    [$product, $id] = cntFixture(10, 7);

    cnt()->submitCount($product, 10, 5, 'short', inventoryActor($product));

    expect(cntMovements($id, 'write_off'))->toBe(0);
});

it('resolves every open discrepancy of the Inventory with a later applied count, and only then', function () {
    [$product, $id, $store] = cntFixture(10, 7);
    $actor = inventoryActor($product);
    $reservations = app(InventoryReservationService::class);

    $first = cnt()->submitCount($product, 10, 5, 'short-1', $actor)->count;
    $second = cnt()->submitCount($product, 10, 6, 'short-2', $actor)->count;

    // A stale count and another discrepancy resolve nothing.
    expect(cnt()->submitCount($product, 3, 9, 'stale', $actor)->resolvedCountIds)->toBe([])
        ->and(cnt()->submitCount($product, 10, 1, 'short-3', $actor)->resolvedCountIds)->toBe([]);

    $resolver = cnt()->submitCount($product, 10, 7, 'found-them', $actor);

    $third = InventoryCount::query()->where('idempotency_key', 'client:short-3')->sole();

    expect($resolver->count->status)->toBe(InventoryCountStatus::Applied)
        ->and($resolver->resolvedCountIds)->toBe([$first->id, $second->id, $third->id])
        ->and($first->fresh()->status)->toBe(InventoryCountStatus::DiscrepancyResolved)
        ->and((int) $first->fresh()->resolved_by_count_id)->toBe($resolver->count->id)
        ->and($first->fresh()->resolved_at)->not->toBeNull()
        ->and(cntState($id))->toBe(['on_hand' => 7, 'reserved' => 7]);
});

it('resolves with a zero-variance applied count too: the re-count proved physical >= reserved', function () {
    [$product, $id] = cntFixture(10, 7);
    $actor = inventoryActor($product);

    $discrepancy = cnt()->submitCount($product, 10, 5, 'short', $actor)->count;
    $resolver = cnt()->submitCount($product, 10, 10, 'all-there', $actor);

    expect($resolver->adjustment)->toBeNull()
        ->and($resolver->resolvedCountIds)->toBe([$discrepancy->id])
        ->and($discrepancy->fresh()->status)->toBe(InventoryCountStatus::DiscrepancyResolved);
});

it('leaves discrepancies of other Inventories alone', function () {
    $store = Store::factory()->create();
    [$a] = inventoryGenesis($store, 5, 'A');
    [$b] = inventoryGenesis($store, 5, 'B');
    app(InventoryReservationService::class)->reserve(inventoryOrder($store, [[$a, 3]]));

    $open = cnt()->submitCount($a, 5, 1, 'short', inventoryActor($a))->count;
    cnt()->submitCount($b, 5, 5, 'fine', inventoryActor($b));

    expect($open->fresh()->status)->toBe(InventoryCountStatus::DiscrepancyOpen);
});

it('does not re-resolve: a second applied count leaves an already resolved discrepancy on its first resolver', function () {
    [$product] = cntFixture(10, 7);
    $actor = inventoryActor($product);

    $discrepancy = cnt()->submitCount($product, 10, 5, 'short', $actor)->count;
    $first = cnt()->submitCount($product, 10, 10, 'fine-1', $actor);
    $second = cnt()->submitCount($product, 10, 10, 'fine-2', $actor);

    expect($second->resolvedCountIds)->toBe([])
        ->and((int) $discrepancy->fresh()->resolved_by_count_id)->toBe($first->count->id);
});

// ---------------------------------------------------------------------
// the state machine as data
// ---------------------------------------------------------------------

it('defines exactly one transition, and every other status as terminal', function () {
    expect(InventoryCountStatus::DiscrepancyOpen->allowedTransitions())->toBe([InventoryCountStatus::DiscrepancyResolved])
        ->and(InventoryCountStatus::DiscrepancyOpen->isTerminal())->toBeFalse();

    foreach ([InventoryCountStatus::Applied, InventoryCountStatus::Stale, InventoryCountStatus::DiscrepancyResolved] as $status) {
        expect($status->allowedTransitions())->toBe([])->and($status->isTerminal())->toBeTrue();
    }

    expect(array_column(InventoryCountStatus::cases(), 'value'))->toBe(['applied', 'stale', 'discrepancy_open', 'discrepancy_resolved']);
});

// ---------------------------------------------------------------------
// idempotency: stale and discrepancy are stored outcomes, not refusals
// ---------------------------------------------------------------------

it('replays every outcome with the same result and zero second effect', function (int $expected, int $observed, int $reserved, string $status) {
    [$product, $id] = cntFixture(10, $reserved);
    $actor = inventoryActor($product);

    $first = cnt()->submitCount($product, $expected, $observed, 'count', $actor, 'n');
    $state = cntState($id);
    $movements = DB::table('inventory_movements')->count();

    $replay = cnt()->submitCount($product, $expected, $observed, 'count', $actor, 'n');

    expect($first->count->status->value)->toBe($status)
        ->and($replay->replayed)->toBeTrue()
        ->and($replay->count->id)->toBe($first->count->id)
        ->and($replay->count->status)->toBe($first->count->status)
        ->and($replay->adjustment?->id)->toBe($first->adjustment?->id)
        ->and(cntState($id))->toBe($state)
        ->and(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(DB::table('inventory_counts')->count())->toBe(1);
})->with([
    'applied, variance' => [10, 8, 0, 'applied'],
    'applied, no variance' => [10, 10, 0, 'applied'],
    'stale' => [11, 8, 0, 'stale'],
    'discrepancy' => [10, 2, 7, 'discrepancy_open'],
]);

it('replays a resolving count with what it resolved', function () {
    [$product] = cntFixture(10, 7);
    $actor = inventoryActor($product);

    $discrepancy = cnt()->submitCount($product, 10, 5, 'short', $actor)->count;
    $first = cnt()->submitCount($product, 10, 10, 'fine', $actor);

    expect(cnt()->submitCount($product, 10, 10, 'fine', $actor)->resolvedCountIds)->toBe([$discrepancy->id])
        ->and($first->resolvedCountIds)->toBe([$discrepancy->id]);
});

it('never re-decides a replay: a stale outcome stays stale after the snapshot would match again', function () {
    [$product] = cntFixture(10);
    $actor = inventoryActor($product);

    $first = cnt()->submitCount($product, 9, 9, 'count', $actor);

    expect($first->count->status)->toBe(InventoryCountStatus::Stale);

    app(InventoryControlService::class)->writeOff($product, 1, 'w', $actor, 'n');

    $replay = cnt()->submitCount($product, 9, 9, 'count', $actor);

    expect($replay->replayed)->toBeTrue()
        ->and($replay->count->status)->toBe(InventoryCountStatus::Stale);
});

it('refuses the same key with a different request, with zero effect', function (int $expected, int $observed, ?string $note) {
    [$product, $id] = cntFixture(10);
    $actor = inventoryActor($product);

    cnt()->submitCount($product, 10, 8, 'count', $actor, 'n');
    $state = cntState($id);

    expect(fn () => cnt()->submitCount($product, $expected, $observed, 'count', $actor, $note))->toThrow(InventoryIdempotencyConflictException::class)
        ->and(cntState($id))->toBe($state)
        ->and(DB::table('inventory_counts')->count())->toBe(1);
})->with([
    'other expected' => [9, 8, 'n'],
    'other observed' => [10, 7, 'n'],
    'other note' => [10, 8, 'm'],
    'no note' => [10, 8, null],
]);

it('keeps count keys and movement keys in separate spaces: stated, not hidden', function () {
    [$product] = cntFixture(10);
    $actor = inventoryActor($product);

    app(InventoryControlService::class)->receive($product, 1, 'same-key', $actor, 'n');

    expect(cnt()->submitCount($product, 11, 11, 'same-key', $actor)->replayed)->toBeFalse();
});

// ---------------------------------------------------------------------
// refusals
// ---------------------------------------------------------------------

it('refuses a legacy Inventory: no baseline, no count, and the observation leaves no row', function () {
    $store = Store::factory()->create();
    $product = inventoryProduct($store);
    inventorySeed($product, 5);

    expect(fn () => cnt()->submitCount($product, 5, 5, 'count', inventoryActor($product)))->toThrow(InventoryNotBaselinedException::class)
        ->and(DB::table('inventory_counts')->count())->toBe(0);
});

it('refuses anyone but the Store owner, before any write', function () {
    [$product] = cntFixture(5);
    $stranger = new StoreOwnerActor(Store::factory()->create()->user_id);

    expect(fn () => cnt()->submitCount($product, 5, 5, 'count', $stranger))->toThrow(InvalidInventoryOperationException::class, 'does not own')
        ->and(DB::table('inventory_counts')->count())->toBe(0);
});

it('refuses quantities that are not strict non-negative integers, a malformed key and a blank note', function () {
    [$product] = cntFixture(5);
    $actor = inventoryActor($product);

    foreach ([-1, 1.0, '5', true, null] as $bad) {
        expect(fn () => cnt()->submitCount($product, $bad, 5, 'count', $actor))->toThrow(InvalidInventoryOperationException::class)
            ->and(fn () => cnt()->submitCount($product, 5, $bad, 'count', $actor))->toThrow(InvalidInventoryOperationException::class);
    }

    expect(fn () => cnt()->submitCount($product, 5, 5, 'BAD', $actor))->toThrow(InvalidInventoryOperationException::class, 'idempotency key')
        ->and(fn () => cnt()->submitCount($product, 5, 5, 'count', $actor, '  '))->toThrow(InvalidInventoryOperationException::class, 'note')
        ->and(DB::table('inventory_counts')->count())->toBe(0);
});

it('accepts the full BIGINT range as an observation, and takes the adjustment across it', function () {
    [$product, $id] = cntFixture(0);

    $result = cnt()->submitCount($product, 0, PHP_INT_MAX, 'count', inventoryActor($product));

    expect($result->adjustment->quantity_delta)->toBe(PHP_INT_MAX)
        ->and(cntState($id)['on_hand'])->toBe(PHP_INT_MAX);
});

it('offers exactly one operation: no acknowledge, dismiss or resolve path exists', function () {
    $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), (new ReflectionClass(InventoryCountService::class))->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->toEqualCanonicalizing(['__construct', 'submitCount']);
});
