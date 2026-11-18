<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\DTOs\CountResult;
use App\Domain\Inventory\DTOs\LockedInventory;
use App\Domain\Inventory\DTOs\StoreOwnerActor;
use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryCountStatus;
use App\Domain\Inventory\Exceptions\InvalidInventoryOperationException;
use App\Domain\Inventory\Exceptions\InventoryIdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InventoryNotBaselinedException;
use App\Domain\Inventory\Models\InventoryCount;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Support\InventoryInput;
use App\Domain\Inventory\Support\InventoryRetry;
use App\Domain\Inventory\Support\InventoryTarget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Physical-count observations and their discrepancy state machine — see
 * docs/inventory/INVENTORY-CONTROL.md §6.7, §7 and §16.
 *
 * ## IMPLEMENTED AND PROVEN, NOT ACTIVATED (STOCK-22)
 *
 * This class must have NO production caller until the selling policy for an open
 * discrepancy (O-1) is decided and the commit-timing question behind O-8 is
 * resolved. While a discrepancy is open the system still reports the units it
 * calls available, although the observation says they may not exist; detecting
 * that is not an operational feature until the system has a policy for what
 * happens next. tests/Architecture/InventoryBoundaryTest pins an empty caller
 * allowlist. Adding a caller is a deliberate change citing those decisions.
 *
 * ## Observed is not authoritative
 *
 * A submission carries what was observed and the `on_hand` it was counted against.
 * Under the Inventory row lock, it is decided from the row itself:
 *
 * - the snapshot no longer matches `on_hand` -> `stale`. Any movement since the
 *   count must not be undone, and nothing here guesses: re-count.
 * - else the observation is below `reserved` -> `discrepancy_open`. Writing it
 *   would break `reserved <= on_hand`, so `on_hand` stays and the evidence is kept.
 * - else -> `applied`: `on_hand := observed` under the snapshot's compare-and-set,
 *   with a movement if the variance is not zero.
 *
 * `stale` and `discrepancy_open` are stored outcomes, not refusals, and a replay
 * of the key returns them. A re-count takes a new key.
 *
 * ## What this class deliberately does NOT do
 *
 * It releases, cancels and modifies no reservation. It converts no shortage into
 * a write-off. Only a later applied count supersedes a discrepancy. It says
 * nothing about committed units not yet dispatched (O-8, BLOCKED).
 */
class InventoryCountService
{
    private const IDENTITY_CONSTRAINT = 'inventory_counts_idempotency_unique';

    public function __construct(
        private readonly InventoryMovementWriter $movements = new InventoryMovementWriter,
    ) {}

    /**
     * @param  int  $expectedOnHand  checked at runtime; deliberately untyped, see InventoryInput
     * @param  int  $observedQuantity  checked at runtime; deliberately untyped, see InventoryInput
     *
     * @throws InvalidInventoryOperationException
     * @throws InventoryIdempotencyConflictException
     * @throws InventoryNotBaselinedException
     */
    public function submitCount(Product|int $product, mixed $expectedOnHand, mixed $observedQuantity, string $key, StoreOwnerActor $actor, ?string $note = null): CountResult
    {
        $expected = InventoryInput::countQuantity($expectedOnHand);
        $observed = InventoryInput::countQuantity($observedQuantity);
        $identity = InventoryInput::clientKey($key);
        $note = $note === null ? null : InventoryInput::note($note);

        $productId = InventoryTarget::productId($product);

        InventoryTarget::assertOwner($productId, $actor);

        $inventoryId = InventoryTarget::inventoryId($productId);

        return InventoryRetry::run(
            function () use ($inventoryId, $identity, $expected, $observed, $actor, $note) {
                $existing = InventoryCount::query()
                    ->where('inventory_id', $inventoryId)
                    ->where('idempotency_key', $identity)
                    ->first();

                if ($existing !== null) {
                    return $this->replay($existing, $inventoryId, $identity, $expected, $observed, $actor, $note);
                }

                return DB::transaction(function () use ($inventoryId, $identity, $expected, $observed, $actor, $note) {
                    $locked = $this->movements->lock($inventoryId);

                    if (! $locked->isBaselined) {
                        throw InventoryNotBaselinedException::forInventory($inventoryId);
                    }

                    $now = now();
                    $status = $this->decide($locked, $expected, $observed);

                    $countId = (int) DB::table('inventory_counts')->insertGetId([
                        'inventory_id' => $inventoryId,
                        'idempotency_key' => $identity,
                        'expected_on_hand' => $expected,
                        'observed_quantity' => $observed,
                        'on_hand_at_submission' => $locked->onHand,
                        'reserved_at_submission' => $locked->reserved,
                        'status' => $status->value,
                        'actor_type' => InventoryActorType::StoreOwner->value,
                        'actor_user_id' => $actor->userId,
                        'note' => $note,
                        'created_at' => $now,
                    ]);

                    $adjustmentId = null;
                    $resolved = [];

                    if ($status === InventoryCountStatus::Applied) {
                        if ($observed !== $locked->onHand) {
                            $adjustmentId = $this->movements->applyCount($inventoryId, $expected, $observed, $countId, $actor->userId, $now);
                        }

                        $resolved = $this->resolveDiscrepancies($inventoryId, $countId, $now);
                    }

                    return new CountResult(
                        InventoryCount::query()->findOrFail($countId),
                        $adjustmentId === null ? null : InventoryMovement::query()->findOrFail($adjustmentId),
                        $resolved,
                        replayed: false,
                    );
                });
            },
            fn (QueryException $e) => InventoryRetry::isUniqueViolationOf($e, self::IDENTITY_CONSTRAINT, 'inventory_counts.idempotency_key'),
        );
    }

    /** Stale first: against a moved `on_hand`, comparing with `reserved` would be meaningless. */
    private function decide(LockedInventory $locked, int $expected, int $observed): InventoryCountStatus
    {
        return match (true) {
            $locked->onHand !== $expected => InventoryCountStatus::Stale,
            $observed < $locked->reserved => InventoryCountStatus::DiscrepancyOpen,
            default => InventoryCountStatus::Applied,
        };
    }

    /**
     * The one permitted change of a count row: a compare-and-set, so an already
     * resolved row is untouched. Only the transaction of an applied count on the
     * same Inventory gets here, under that Inventory's lock.
     *
     * @return list<int>
     */
    private function resolveDiscrepancies(int $inventoryId, int $countId, mixed $now): array
    {
        DB::table('inventory_counts')
            ->where('inventory_id', $inventoryId)
            ->where('status', InventoryCountStatus::DiscrepancyOpen->value)
            ->update([
                'status' => InventoryCountStatus::DiscrepancyResolved->value,
                'resolved_by_count_id' => $countId,
                'resolved_at' => $now,
            ]);

        return $this->resolvedBy($countId);
    }

    /** @return list<int> */
    private function resolvedBy(int $countId): array
    {
        return InventoryCount::query()
            ->where('resolved_by_count_id', $countId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function replay(InventoryCount $existing, int $inventoryId, string $identity, int $expected, int $observed, StoreOwnerActor $actor, ?string $note): CountResult
    {
        $sameRequest = $existing->expected_on_hand === $expected
            && $existing->observed_quantity === $observed
            && $existing->actor_user_id === $actor->userId
            && $existing->note === $note;

        if (! $sameRequest) {
            throw InventoryIdempotencyConflictException::forKey($inventoryId, $identity);
        }

        return new CountResult(
            $existing,
            InventoryMovement::query()->where('count_id', $existing->getKey())->first(),
            $this->resolvedBy((int) $existing->getKey()),
            replayed: true,
        );
    }
}
