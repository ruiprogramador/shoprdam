<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTOs\LockedInventory;
use App\Domain\Inventory\DTOs\MovementDraft;
use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InventoryIntegrityException;
use App\Domain\Inventory\Exceptions\InventoryQuantityCeilingException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Inventory\Support\InventoryInput;
use App\Domain\Inventory\Support\InventoryRetry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * @internal The single code path that changes `inventories.on_hand_quantity` and
 * the single inserter of `inventory_movements` — see
 * docs/inventory/INVENTORY-CONTROL.md §13. Callable only by the canonical
 * inventory services (tests/Architecture/InventoryBoundaryTest).
 *
 * ## Why this class exists
 *
 * "Every change of on_hand has exactly one movement" (STOCK-01) is structural
 * here, not a convention repeated across services: each public mutation is one
 * guarded UPDATE of `on_hand` plus the one movement evidencing it. Neither can
 * exist without the other, because they run in the caller's transaction and a
 * failure that rolls it back takes both. Rollback is the only compensation, and a
 * caller's transaction that swallows a concurrency error and commits never rolls
 * back: see docs/inventory/INVENTORY-CONTROL.md §24.7.
 *
 * ## Preconditions, owned by the caller
 *
 * A transaction is open (asserted) and the Inventory row is already locked by
 * lock() or by the reservation service's own pre-lock. That order is not style.
 * A child INSERT's foreign key takes a SHARED lock on its `inventories` parent,
 * which is compatible with this transaction's EXCLUSIVE lock; without that lock
 * first, two writers each holding S and then asking for X deadlock on InnoDB
 * (found by tests/Concurrency, invisible on SQLite).
 *
 * ## Quantity decisions are made by the database
 *
 * Every guard is the predicate of one conditional UPDATE, and its affected-row
 * count is the answer. No quantity read here decides a mutation.
 */
class InventoryMovementWriter
{
    /** BIGINT's ceiling, which is also PHP_INT_MAX on every supported platform. */
    private const MAX_ON_HAND = 9223372036854775807;

    private const IDENTITY_CONSTRAINT = 'inventory_movements_idempotency_unique';

    /**
     * The Inventory row's exclusive lock, and its current values: a locking read
     * is a current read, whatever snapshot this transaction already holds.
     */
    public function lock(int $inventoryId): LockedInventory
    {
        $row = Inventory::query()->whereKey($inventoryId)->lockForUpdate()->toBase()
            ->first(['id', 'on_hand_quantity', 'reserved_quantity', 'is_baselined']);

        if ($row === null) {
            throw InventoryIntegrityException::inventoryVanished($inventoryId);
        }

        return new LockedInventory(
            (int) $row->id,
            (int) $row->on_hand_quantity,
            (int) $row->reserved_quantity,
            (bool) $row->is_baselined,
        );
    }

    /** `on_hand += q`, refused before the arithmetic could overflow. The insert is the claim. */
    public function increase(int $inventoryId, int $quantity, MovementDraft $draft): int
    {
        $this->assertInTransaction();

        $now = now();
        $movementId = $this->insert($inventoryId, $quantity, $draft, $now);

        $affected = DB::table('inventories')
            ->where('id', $inventoryId)
            ->where('on_hand_quantity', '<=', self::MAX_ON_HAND - $quantity)
            ->update([
                'on_hand_quantity' => DB::raw('on_hand_quantity + '.$quantity),
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            throw InventoryQuantityCeilingException::forInventory($inventoryId, $quantity);
        }

        return $movementId;
    }

    /** `on_hand -= q`, only within `available`: reserved stock is never consumed. The insert is the claim. */
    public function decreaseAvailable(int $inventoryId, int $quantity, MovementDraft $draft): int
    {
        $this->assertInTransaction();

        $now = now();
        $movementId = $this->insert($inventoryId, -$quantity, $draft, $now);

        $affected = DB::table('inventories')
            ->where('id', $inventoryId)
            ->whereRaw('on_hand_quantity - reserved_quantity >= ?', [$quantity])
            ->update([
                'on_hand_quantity' => DB::raw('on_hand_quantity - '.$quantity),
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            throw $draft->reason === InventoryMovementReason::Correction
                ? InsufficientStockException::forCorrection($inventoryId, $quantity)
                : InsufficientStockException::forWriteOff($inventoryId, $quantity);
        }

        return $movementId;
    }

    /**
     * `on_hand := observed` under the compare-and-set of the count's snapshot,
     * then the movement. The caller decided the count from the locked row, so a
     * zero here is a contradiction under the lock, never an outcome.
     */
    public function applyCount(int $inventoryId, int $expected, int $observed, int $countId, int $actorUserId, mixed $now): int
    {
        $this->assertInTransaction();

        $affected = DB::table('inventories')
            ->where('id', $inventoryId)
            ->where('on_hand_quantity', $expected)
            ->where('reserved_quantity', '<=', $observed)
            ->update([
                'on_hand_quantity' => $observed,
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            throw InventoryIntegrityException::countCasFailedUnderLock($inventoryId);
        }

        return $this->insertRow($inventoryId, $observed - $expected, $now, [
            'reason' => InventoryMovementReason::CountAdjustment->value,
            'idempotency_key' => InventoryInput::countAdjustmentKey($countId),
            'count_id' => $countId,
            'actor_type' => InventoryActorType::StoreOwner->value,
            'actor_user_id' => $actorUserId,
            'after_baseline' => true,
        ]);
    }

    /**
     * The counter half of a commit this transaction just won (the reservation's
     * compare-and-set is the claim), then its sale evidence. The predicates make
     * a drifted counter refuse, rolling the whole transaction back, CAS included.
     *
     * A unique violation on the sale identity is corruption, never a replay: only
     * the CAS winner gets here, so the row can only pre-exist if the reservation's
     * status was rewritten. It surfaces as an integrity failure and is not retried.
     */
    public function consumeReservation(InventoryReservation $reservation, mixed $now): void
    {
        $this->assertInTransaction();

        $reservationId = (int) $reservation->getKey();
        $inventoryId = (int) $reservation->inventory_id;
        $quantity = (int) $reservation->quantity;

        $affected = DB::table('inventories')
            ->where('id', $inventoryId)
            ->where('reserved_quantity', '>=', $quantity)
            ->where('on_hand_quantity', '>=', $quantity)
            ->update([
                'on_hand_quantity' => DB::raw('on_hand_quantity - '.$quantity),
                'reserved_quantity' => DB::raw('reserved_quantity - '.$quantity),
                'updated_at' => $now,
            ]);

        if ($affected !== 1) {
            Log::error('Inventory counters cannot absorb a reservation transition; rolled back.', [
                'reservation_id' => $reservationId,
                'inventory_id' => $inventoryId,
                'target' => 'committed',
                'quantity' => $quantity,
            ]);

            throw InventoryIntegrityException::counterDrift($reservationId, $inventoryId);
        }

        // A locking read of the row this transaction already holds exclusively:
        // no wait, and a current read whatever snapshot this transaction holds.
        // A plain SELECT would only be right here by accident: it happens to see
        // the version the UPDATE above just wrote. Moved before that UPDATE, it
        // stamps a sale as pre-adoption on real MySQL when adopt() committed in
        // between (proven by probe, docs/inventory/INVENTORY-CONTROL.md §24).
        $isBaselined = (bool) Inventory::query()->whereKey($inventoryId)->lockForUpdate()->toBase()->value('is_baselined');

        try {
            $this->insertRow($inventoryId, -$quantity, $now, [
                'reason' => InventoryMovementReason::Sale->value,
                'idempotency_key' => InventoryInput::saleKey($reservationId),
                'reservation_id' => $reservationId,
                'actor_type' => InventoryActorType::System->value,
                'after_baseline' => $isBaselined,
            ]);
        } catch (QueryException $e) {
            if (InventoryRetry::isUniqueViolationOf($e, self::IDENTITY_CONSTRAINT, 'inventory_movements.idempotency_key')) {
                Log::error('A reservation won its commit but already has sale evidence; rolled back.', [
                    'reservation_id' => $reservationId,
                    'inventory_id' => $inventoryId,
                ]);

                throw InventoryIntegrityException::duplicateSaleEvidence($reservationId, $inventoryId);
            }

            throw $e;
        }
    }

    /** Whether a database error is a movement's identity claim being lost. */
    public static function isIdentityViolation(QueryException $e): bool
    {
        return InventoryRetry::isUniqueViolationOf($e, self::IDENTITY_CONSTRAINT, 'inventory_movements.idempotency_key');
    }

    private function insert(int $inventoryId, int $delta, MovementDraft $draft, mixed $now): int
    {
        return $this->insertRow($inventoryId, $delta, $now, [
            'reason' => $draft->reason->value,
            'idempotency_key' => $draft->idempotencyKey,
            'corrects_movement_id' => $draft->correctsMovementId,
            'actor_type' => InventoryActorType::StoreOwner->value,
            'actor_user_id' => $draft->actorUserId,
            'note' => $draft->note,
            'after_baseline' => $draft->afterBaseline,
        ]);
    }

    /** @param  array<string, mixed>  $columns */
    private function insertRow(int $inventoryId, int $delta, mixed $now, array $columns): int
    {
        return (int) DB::table('inventory_movements')->insertGetId([
            'inventory_id' => $inventoryId,
            'quantity_delta' => $delta,
            'created_at' => $now,
            ...$columns,
        ]);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An on-hand change and its movement must share one transaction.');
        }
    }
}
