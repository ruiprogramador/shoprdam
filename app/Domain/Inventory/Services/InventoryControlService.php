<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\DTOs\LockedInventory;
use App\Domain\Inventory\DTOs\MovementDraft;
use App\Domain\Inventory\DTOs\MovementResult;
use App\Domain\Inventory\DTOs\StoreOwnerActor;
use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryBaselineKind;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use App\Domain\Inventory\Exceptions\InvalidInventoryOperationException;
use App\Domain\Inventory\Exceptions\InventoryIdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InventoryIntegrityException;
use App\Domain\Inventory\Exceptions\InventoryNotBaselinedException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\InventoryBaseline;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\InventoryInput;
use App\Domain\Inventory\Support\InventoryRetry;
use App\Domain\Inventory\Support\InventoryTarget;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Inventory lifecycle (createInventory, adopt) and the operator's stock events
 * (receive, writeOff, correct) — see docs/inventory/INVENTORY-CONTROL.md §6.
 * Stock is written only through InventoryMovementWriter.
 *
 * There is deliberately no `setStock` and no generic `adjust`: every legitimate
 * manual change is one of these named operations with its own guard (§6.9).
 *
 * ## One shape for every mutation
 *
 * 1. Validate, touching no database.
 * 2. Resolve the target and refuse anyone but the Store owner.
 * 3. Replay pre-check, outside the mutating transaction: an identical earlier
 *    request returns its stored row, any other payload is refused.
 * 4. In one transaction: lock the Inventory row first, decide from what that
 *    locking read returned, insert the unique claim, run the guarded UPDATE.
 *
 * A concurrent identical request loses the claim, rolls back completely and is
 * retried from step 3, which now replays the winner. A refusal rolls back too
 * and leaves no row: idempotency covers effects, not refusals.
 *
 * ## What this class deliberately does NOT do
 *
 * No Payment/Wallet/provider knowledge and no HTTP. No price or currency. No
 * return or restock (an open product decision), and no release of any
 * reservation: a shortage inside reserved stock is a count's discrepancy, never
 * a write-off. Nothing in production calls this class yet.
 */
class InventoryControlService
{
    private const PRODUCT_CONSTRAINT = 'inventories_product_id_unique';

    private const BASELINE_CONSTRAINT = 'inventory_baselines_inventory_unique';

    public function __construct(
        private readonly InventoryMovementWriter $movements = new InventoryMovementWriter,
    ) {}

    /**
     * The canonical birth of an Inventory: 0/0, with its `genesis` baseline, all
     * or nothing. Catalog never calls this. `wasRecentlyCreated` tells a creation
     * from an idempotent replay. A legacy row is refused rather than returned:
     * that would hide its unevidenced state.
     *
     * @throws InvalidInventoryOperationException
     */
    public function createInventory(Product|int $product, StoreOwnerActor $actor): Inventory
    {
        $productId = InventoryTarget::productId($product);

        if (InventoryTarget::assertOwner($productId, $actor)) {
            throw InvalidInventoryOperationException::productDeleted($productId);
        }

        return InventoryRetry::run(
            function () use ($productId, $actor) {
                $existing = Inventory::query()->where('product_id', $productId)->first();

                if ($existing !== null) {
                    return $this->replayCreation($existing, $productId);
                }

                return DB::transaction(function () use ($productId, $actor) {
                    $now = now();

                    // The unique(product_id) claim. No parent Inventory exists to
                    // lock; the FK's shared lock on `products` is never upgraded.
                    $inventoryId = (int) DB::table('inventories')->insertGetId([
                        'product_id' => $productId,
                        'on_hand_quantity' => 0,
                        'reserved_quantity' => 0,
                        'is_baselined' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('inventory_baselines')->insert([
                        'inventory_id' => $inventoryId,
                        'kind' => InventoryBaselineKind::Genesis->value,
                        'on_hand_at_baseline' => 0,
                        'reserved_at_baseline' => 0,
                        'actor_type' => InventoryActorType::StoreOwner->value,
                        'actor_user_id' => $actor->userId,
                        'created_at' => $now,
                    ]);

                    $inventory = Inventory::query()->findOrFail($inventoryId);
                    $inventory->wasRecentlyCreated = true;

                    return $inventory;
                });
            },
            fn (QueryException $e) => InventoryRetry::isUniqueViolationOf($e, self::PRODUCT_CONSTRAINT, 'inventories.product_id'),
        );
    }

    /**
     * Adopts a legacy Inventory as it stands: "evidence starts here", not "this
     * was always true". Changes no quantity and fabricates no movement. Movements
     * that pre-date it stay in history, excluded from the reconciliation by their
     * own stamp, so nothing is summed here and no read view matters.
     *
     * @throws InvalidInventoryOperationException
     * @throws InventoryIdempotencyConflictException
     */
    public function adopt(Product|int $product, StoreOwnerActor $actor, string $note): InventoryBaseline
    {
        $note = InventoryInput::note($note);
        $productId = InventoryTarget::productId($product);

        InventoryTarget::assertOwner($productId, $actor);

        $inventoryId = InventoryTarget::inventoryId($productId);

        return InventoryRetry::run(
            function () use ($inventoryId, $productId, $actor, $note) {
                $existing = InventoryBaseline::query()->where('inventory_id', $inventoryId)->first();

                if ($existing !== null) {
                    return $this->replayAdoption($existing, $inventoryId, $productId, $actor, $note);
                }

                $adopted = DB::transaction(function () use ($inventoryId, $actor, $note) {
                    $locked = $this->movements->lock($inventoryId);

                    if ($locked->isBaselined) {
                        return null; // committed since the pre-check: replay outside this transaction
                    }

                    $baselineId = (int) DB::table('inventory_baselines')->insertGetId([
                        'inventory_id' => $inventoryId,
                        'kind' => InventoryBaselineKind::Adopted->value,
                        'on_hand_at_baseline' => $locked->onHand,
                        'reserved_at_baseline' => $locked->reserved,
                        'actor_type' => InventoryActorType::StoreOwner->value,
                        'actor_user_id' => $actor->userId,
                        'note' => $note,
                        'created_at' => now(),
                    ]);

                    $affected = DB::table('inventories')
                        ->where('id', $inventoryId)
                        ->where('is_baselined', false)
                        ->update(['is_baselined' => true, 'updated_at' => now()]);

                    if ($affected !== 1) {
                        throw InventoryIntegrityException::baselineFlagCasFailedUnderLock($inventoryId);
                    }

                    return InventoryBaseline::query()->findOrFail($baselineId);
                });

                if ($adopted !== null) {
                    return $adopted;
                }

                $winner = InventoryBaseline::query()->where('inventory_id', $inventoryId)->first()
                    ?? throw InventoryIntegrityException::baselineFlagMismatch($inventoryId);

                return $this->replayAdoption($winner, $inventoryId, $productId, $actor, $note);
            },
            fn (QueryException $e) => InventoryRetry::isUniqueViolationOf($e, self::BASELINE_CONSTRAINT, 'inventory_baselines.inventory_id'),
        );
    }

    /**
     * Goods arrived: `on_hand += q`, `reserved` untouched. Nothing about origin
     * is modeled beyond the note.
     *
     * @param  int  $quantity  checked at runtime; deliberately untyped, see InventoryInput
     *
     * @throws InvalidInventoryOperationException
     * @throws InventoryIdempotencyConflictException
     * @throws InventoryNotBaselinedException
     */
    public function receive(Product|int $product, mixed $quantity, string $key, StoreOwnerActor $actor, string $note): MovementResult
    {
        $quantity = InventoryInput::operationQuantity($quantity);
        $identity = InventoryInput::clientKey($key);
        $note = InventoryInput::note($note);

        $inventoryId = $this->targetInventory($product, $actor);

        return $this->mutate(
            $inventoryId,
            $identity,
            fn (InventoryMovement $row) => $row->reason === InventoryMovementReason::Receipt
                && abs($row->quantity_delta) === $quantity
                && $row->actor_user_id === $actor->userId
                && $row->note === $note,
            fn (LockedInventory $locked) => $this->movements->increase($inventoryId, $quantity, new MovementDraft(
                InventoryMovementReason::Receipt, $identity, $actor->userId, $locked->isBaselined, $note,
            )),
        );
    }

    /**
     * Goods lost, damaged or broken: `on_hand -= q`, only within `available`.
     * Reserved stock is never consumed; a shortage inside it is observed through
     * a count, never converted into a write-off.
     *
     * @param  int  $quantity  checked at runtime; deliberately untyped, see InventoryInput
     *
     * @throws InvalidInventoryOperationException
     * @throws InventoryIdempotencyConflictException
     * @throws InventoryNotBaselinedException
     */
    public function writeOff(Product|int $product, mixed $quantity, string $key, StoreOwnerActor $actor, string $note): MovementResult
    {
        $quantity = InventoryInput::operationQuantity($quantity);
        $identity = InventoryInput::clientKey($key);
        $note = InventoryInput::note($note);

        $inventoryId = $this->targetInventory($product, $actor);

        return $this->mutate(
            $inventoryId,
            $identity,
            fn (InventoryMovement $row) => $row->reason === InventoryMovementReason::WriteOff
                && abs($row->quantity_delta) === $quantity
                && $row->actor_user_id === $actor->userId
                && $row->note === $note,
            fn (LockedInventory $locked) => $this->movements->decreaseAvailable($inventoryId, $quantity, new MovementDraft(
                InventoryMovementReason::WriteOff, $identity, $actor->userId, $locked->isBaselined, $note,
            )),
        );
    }

    /**
     * The exact, full reversal of a mistaken receipt or write-off, under the
     * guard of its own direction: stock since reserved is never un-received. At
     * most one per movement: the identity is derived from the original, so a
     * second, different correction is refused as a conflict. The right value is
     * then entered as an ordinary new movement.
     *
     * @throws InvalidInventoryOperationException
     * @throws InventoryIdempotencyConflictException
     * @throws InventoryNotBaselinedException
     */
    public function correct(Product|int $product, int $movementId, StoreOwnerActor $actor, string $note): MovementResult
    {
        $note = InventoryInput::note($note);
        $productId = InventoryTarget::productId($product);
        $inventoryId = $this->targetInventory($productId, $actor);

        // A plain read is enough: a movement never changes once written.
        $original = InventoryMovement::query()->find($movementId);

        if ($original === null || (int) $original->inventory_id !== $inventoryId) {
            throw InvalidInventoryOperationException::movementMismatch($movementId, $productId);
        }

        if (! $original->reason->isCorrectable()) {
            throw InvalidInventoryOperationException::notCorrectable($movementId, $original->reason);
        }

        $identity = InventoryInput::correctionKey($movementId);
        $quantity = abs($original->quantity_delta);
        $reversesIncrease = $original->quantity_delta > 0;

        return $this->mutate(
            $inventoryId,
            $identity,
            fn (InventoryMovement $row) => (int) $row->corrects_movement_id === $movementId
                && $row->actor_user_id === $actor->userId
                && $row->note === $note,
            function (LockedInventory $locked) use ($inventoryId, $quantity, $reversesIncrease, $identity, $actor, $note, $movementId) {
                $draft = new MovementDraft(
                    InventoryMovementReason::Correction, $identity, $actor->userId, $locked->isBaselined, $note, $movementId,
                );

                return $reversesIncrease
                    ? $this->movements->decreaseAvailable($inventoryId, $quantity, $draft)
                    : $this->movements->increase($inventoryId, $quantity, $draft);
            },
        );
    }

    private function targetInventory(Product|int $product, StoreOwnerActor $actor): int
    {
        $productId = InventoryTarget::productId($product);

        InventoryTarget::assertOwner($productId, $actor);

        return InventoryTarget::inventoryId($productId);
    }

    /**
     * @param  Closure(InventoryMovement): bool  $isSameRequest
     * @param  Closure(LockedInventory): int  $apply
     */
    private function mutate(int $inventoryId, string $identity, Closure $isSameRequest, Closure $apply): MovementResult
    {
        return InventoryRetry::run(
            function () use ($inventoryId, $identity, $isSameRequest, $apply) {
                $existing = InventoryMovement::query()
                    ->where('inventory_id', $inventoryId)
                    ->where('idempotency_key', $identity)
                    ->first();

                if ($existing !== null) {
                    if (! $isSameRequest($existing)) {
                        throw InventoryIdempotencyConflictException::forKey($inventoryId, $identity);
                    }

                    return new MovementResult($existing, replayed: true);
                }

                return DB::transaction(function () use ($inventoryId, $apply) {
                    $locked = $this->movements->lock($inventoryId);

                    if (! $locked->isBaselined) {
                        throw InventoryNotBaselinedException::forInventory($inventoryId);
                    }

                    return new MovementResult(InventoryMovement::query()->findOrFail($apply($locked)), replayed: false);
                });
            },
            InventoryMovementWriter::isIdentityViolation(...),
        );
    }

    private function replayCreation(Inventory $existing, int $productId): Inventory
    {
        $kind = InventoryBaseline::query()->where('inventory_id', $existing->getKey())->first()?->kind;

        return match ($kind) {
            InventoryBaselineKind::Genesis => $existing,
            InventoryBaselineKind::Adopted => throw InvalidInventoryOperationException::alreadyBaselined($productId),
            null => throw InvalidInventoryOperationException::requiresAdoption($productId),
        };
    }

    private function replayAdoption(InventoryBaseline $baseline, int $inventoryId, int $productId, StoreOwnerActor $actor, string $note): InventoryBaseline
    {
        // Read after the baseline, which was committed with the flag: a false here
        // is a raw rewrite, and replaying over it would hide the corruption.
        if (! (bool) Inventory::query()->whereKey($inventoryId)->toBase()->value('is_baselined')) {
            throw InventoryIntegrityException::baselineFlagMismatch($inventoryId);
        }

        if ($baseline->kind !== InventoryBaselineKind::Adopted) {
            throw InvalidInventoryOperationException::alreadyBaselined($productId);
        }

        if ($baseline->actor_user_id !== $actor->userId || $baseline->note !== $note) {
            throw InventoryIdempotencyConflictException::forAdoption($inventoryId);
        }

        return $baseline;
    }
}
