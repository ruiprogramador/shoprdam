<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\InventoryActorType;
use App\Domain\Inventory\Enums\InventoryBaselineKind;
use App\Domain\Inventory\Enums\InventoryCountStatus;
use App\Domain\Inventory\Enums\InventoryMovementReason;
use App\Domain\Inventory\Enums\InventoryReservationStatus;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\InventoryBaseline;
use App\Domain\Inventory\Models\InventoryCount;
use App\Domain\Inventory\Models\InventoryMovement;
use App\Domain\Inventory\Models\InventoryReservation;
use Illuminate\Support\Collection;

/**
 * Read-only drift detector for the inventory tables (INVENTORY-20) — the
 * inventory counterpart of App\Domain\Orders\Services\OrderLineIntegrityChecker
 * and the wallet ledger auditor. It DETECTS and REPORTS; it never repairs,
 * recomputes or rewrites a counter, and it holds no lock. Nothing calls it
 * automatically: an operator (or a future scheduled report) runs `audit()`.
 *
 * What it can and cannot see:
 *
 * - it can see counters that contradict their own CHECKs, `reserved_quantity`
 *   that disagrees with the sum of the `reserved` rows, a reservation that
 *   disagrees with its OrderItem or Inventory, and an unknown status;
 * - for an Inventory with a baseline it verifies the reconciliation equation
 *   `on_hand = on_hand_at_baseline + Σ quantity_delta WHERE after_baseline`
 *   (docs/inventory/INVENTORY-CONTROL.md §15), and that every piece of evidence
 *   agrees with what it claims to evidence;
 * - it cannot verify an Inventory without a baseline: the equation is not
 *   evaluated there, and evidenceReport() lists it as unbaselined, never as
 *   consistent. audit() returns corruption only;
 * - the equation proves internal consistency from a baseline, never physical
 *   truth, and two coordinated raw writes (a movement plus a matching `on_hand`
 *   edit) stay undetectable.
 *
 * On a live database it reads an Inventory and its sums in separate statements,
 * so a transition in between can look like drift. The equation is confirmed by
 * an immediate re-check of that Inventory before it is reported; the
 * pre-existing `counter_disagrees_with_reservations` check has no such re-check
 * and keeps that known transient exposure.
 *
 * Bounded: every pass walks its table with `chunkById()`; nothing loads a whole
 * table.
 */
class InventoryIntegrityChecker
{
    public const NEGATIVE_ON_HAND = 'negative_on_hand';

    public const NEGATIVE_RESERVED = 'negative_reserved';

    public const RESERVED_EXCEEDS_ON_HAND = 'reserved_exceeds_on_hand';

    public const COUNTER_DISAGREES_WITH_RESERVATIONS = 'counter_disagrees_with_reservations';

    public const UNKNOWN_STATUS = 'unknown_status';

    public const ORDER_ITEM_MISSING = 'order_item_missing';

    public const QUANTITY_MISMATCH = 'quantity_mismatch';

    public const INVENTORY_PRODUCT_MISMATCH = 'inventory_product_mismatch';

    public const ON_HAND_DISAGREES_WITH_EVIDENCE = 'on_hand_disagrees_with_evidence';

    public const BASELINE_INVALID = 'baseline_invalid';

    public const BASELINE_FLAG_MISMATCH = 'baseline_flag_mismatch';

    public const MOVEMENT_BASELINE_STAMP_INVALID = 'movement_baseline_stamp_invalid';

    public const MOVEMENT_ACTOR_INVALID = 'movement_actor_invalid';

    public const SALE_RESERVATION_NOT_COMMITTED = 'sale_reservation_not_committed';

    public const SALE_MISMATCH = 'sale_mismatch';

    public const DUPLICATE_SALE_EVIDENCE = 'duplicate_sale_evidence';

    public const COMMITTED_RESERVATION_WITHOUT_SALE = 'committed_reservation_without_sale';

    public const CORRECTION_INVALID = 'correction_invalid';

    public const COUNT_ADJUSTMENT_INVALID = 'count_adjustment_invalid';

    public const COUNT_RESOLUTION_INVALID = 'count_resolution_invalid';

    /** Reported by evidenceReport(), never by audit(): a class of Inventory, not corruption. */
    public const INVENTORY_UNBASELINED = 'inventory_unbaselined';

    /** Reported by evidenceReport(), never by audit(): operational attention, not corruption. */
    public const DISCREPANCY_OPEN = 'discrepancy_open';

    public function __construct(private readonly int $chunkSize = 200) {}

    /**
     * @return list<array{code: string, inventory_id: int|null, reservation_id: int|null, detail: string}> empty when consistent
     */
    public function audit(): array
    {
        $findings = [];

        Inventory::query()->chunkById($this->chunkSize, function (Collection $inventories) use (&$findings) {
            $live = InventoryReservation::query()
                ->whereIn('inventory_id', $inventories->pluck('id')->all())
                ->where('status', InventoryReservationStatus::Reserved->value)
                ->groupBy('inventory_id')
                ->selectRaw('inventory_id, SUM(quantity) as total')
                ->pluck('total', 'inventory_id');

            foreach ($inventories as $inventory) {
                $id = (int) $inventory->id;
                $onHand = (int) $inventory->on_hand_quantity;
                $reserved = (int) $inventory->reserved_quantity;

                if ($onHand < 0) {
                    $findings[] = $this->finding(self::NEGATIVE_ON_HAND, $id, null, "on_hand_quantity is {$onHand}.");
                }

                if ($reserved < 0) {
                    $findings[] = $this->finding(self::NEGATIVE_RESERVED, $id, null, "reserved_quantity is {$reserved}.");
                }

                if ($reserved > $onHand) {
                    $findings[] = $this->finding(self::RESERVED_EXCEEDS_ON_HAND, $id, null, "reserved_quantity {$reserved} exceeds on_hand_quantity {$onHand}.");
                }

                $sum = (int) ($live[$id] ?? 0);

                if ($sum !== $reserved) {
                    $findings[] = $this->finding(self::COUNTER_DISAGREES_WITH_RESERVATIONS, $id, null, "reserved_quantity is {$reserved} but reserved reservations sum to {$sum}.");
                }
            }
        });

        InventoryReservation::query()
            ->with(['orderItem:id,product_id,quantity', 'inventory:id,product_id'])
            ->chunkById($this->chunkSize, function (Collection $reservations) use (&$findings) {
                foreach ($reservations as $reservation) {
                    $id = (int) $reservation->id;
                    $inventoryId = (int) $reservation->inventory_id;

                    if (InventoryReservationStatus::tryFrom((string) $reservation->getRawOriginal('status')) === null) {
                        $findings[] = $this->finding(self::UNKNOWN_STATUS, $inventoryId, $id, "status '{$reservation->getRawOriginal('status')}' is not a known state.");
                    }

                    $item = $reservation->orderItem;

                    if ($item === null) {
                        $findings[] = $this->finding(self::ORDER_ITEM_MISSING, $inventoryId, $id, 'its OrderItem does not exist.');

                        continue;
                    }

                    if ((int) $reservation->quantity !== (int) $item->quantity) {
                        $findings[] = $this->finding(self::QUANTITY_MISMATCH, $inventoryId, $id, "quantity {$reservation->quantity} differs from the OrderItem's {$item->quantity}.");
                    }

                    if ($reservation->inventory === null || (int) $reservation->inventory->product_id !== (int) $item->product_id) {
                        $findings[] = $this->finding(self::INVENTORY_PRODUCT_MISMATCH, $inventoryId, $id, "Inventory #{$inventoryId} is not the Inventory of the item's Product #{$item->product_id}.");
                    }
                }
            });

        $this->auditBaselines($findings);
        $this->auditMovements($findings);
        $this->auditCounts($findings);
        $this->auditCommittedWithoutSale($findings);

        return $findings;
    }

    /**
     * What audit() deliberately does not call corruption but must never present
     * as consistent: Inventories whose evidence cannot be evaluated, and counts
     * awaiting attention.
     *
     * @return array{unbaselined: list<array{code: string, inventory_id: int|null, reservation_id: int|null, detail: string}>, open_discrepancies: list<array{code: string, inventory_id: int|null, reservation_id: int|null, detail: string}>}
     */
    public function evidenceReport(): array
    {
        $unbaselined = [];
        $open = [];

        Inventory::query()->toBase()->where('is_baselined', false)->chunkById($this->chunkSize, function (Collection $inventories) use (&$unbaselined) {
            foreach ($inventories as $inventory) {
                $unbaselined[] = $this->finding(self::INVENTORY_UNBASELINED, (int) $inventory->id, null, 'it has no baseline: its evidence cannot be evaluated.');
            }
        });

        InventoryCount::query()->toBase()->where('status', InventoryCountStatus::DiscrepancyOpen->value)->chunkById($this->chunkSize, function (Collection $counts) use (&$open) {
            foreach ($counts as $count) {
                $open[] = $this->finding(self::DISCREPANCY_OPEN, (int) $count->inventory_id, null, "count #{$count->id} observed {$count->observed_quantity} against {$count->reserved_at_submission} reserved.");
            }
        });

        return ['unbaselined' => $unbaselined, 'open_discrepancies' => $open];
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditBaselines(array &$findings): void
    {
        Inventory::query()->toBase()->chunkById($this->chunkSize, function (Collection $inventories) use (&$findings) {
            $ids = $inventories->pluck('id')->all();

            $baselines = InventoryBaseline::query()->toBase()->whereIn('inventory_id', $ids)->get()->keyBy('inventory_id');

            foreach ($inventories as $inventory) {
                $id = (int) $inventory->id;
                $baseline = $baselines->get($id);

                if ((bool) $inventory->is_baselined !== ($baseline !== null)) {
                    $findings[] = $this->finding(self::BASELINE_FLAG_MISMATCH, $id, null, 'is_baselined and the baseline row disagree.');
                }

                if ($baseline === null) {
                    continue;
                }

                if ($baseline->kind === InventoryBaselineKind::Genesis->value && ((int) $baseline->on_hand_at_baseline !== 0 || (int) $baseline->reserved_at_baseline !== 0)) {
                    $findings[] = $this->finding(self::BASELINE_INVALID, $id, null, 'a genesis baseline must start at 0/0.');
                }

                $expected = $this->evidencedOnHand($id, (int) $baseline->on_hand_at_baseline);

                if ($expected !== (string) $inventory->on_hand_quantity) {
                    // Confirm on fresh reads: a transition between the two reads above is not drift.
                    $current = (string) Inventory::query()->whereKey($id)->toBase()->value('on_hand_quantity');
                    $confirmed = $this->evidencedOnHand($id, (int) $baseline->on_hand_at_baseline);

                    if ($confirmed !== $current) {
                        $findings[] = $this->finding(self::ON_HAND_DISAGREES_WITH_EVIDENCE, $id, null, "on_hand_quantity is {$current} but its baseline and movements give {$confirmed}.");
                    }
                }
            }
        });
    }

    /** BCMath: the sum is checked, never trusted to fit an int or a float. */
    private function evidencedOnHand(int $inventoryId, int $onHandAtBaseline): string
    {
        $sum = InventoryMovement::query()->toBase()
            ->where('inventory_id', $inventoryId)
            ->where('after_baseline', true)
            ->sum('quantity_delta');

        return bcadd((string) $onHandAtBaseline, (string) $sum, 0);
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditMovements(array &$findings): void
    {
        InventoryMovement::query()->toBase()->chunkById($this->chunkSize, function (Collection $movements) use (&$findings) {
            $reservations = InventoryReservation::query()->toBase()->whereIn('id', $movements->pluck('reservation_id')->filter()->all())->get(['id', 'inventory_id', 'quantity', 'status'])->keyBy('id');
            $originals = InventoryMovement::query()->toBase()->whereIn('id', $movements->pluck('corrects_movement_id')->filter()->all())->get(['id', 'inventory_id', 'reason', 'quantity_delta'])->keyBy('id');
            $counts = InventoryCount::query()->toBase()->whereIn('id', $movements->pluck('count_id')->filter()->all())->get(['id', 'inventory_id', 'status', 'observed_quantity', 'on_hand_at_submission'])->keyBy('id');
            $baselines = InventoryBaseline::query()->toBase()->whereIn('inventory_id', $movements->pluck('inventory_id')->all())->pluck('kind', 'inventory_id');

            foreach ($movements as $movement) {
                $this->auditMovementStamp($findings, $movement, $baselines->get($movement->inventory_id));
                $this->auditMovementActor($findings, $movement);

                match (InventoryMovementReason::tryFrom((string) $movement->reason)) {
                    InventoryMovementReason::Sale => $this->auditSale($findings, $movement, $reservations->get($movement->reservation_id)),
                    InventoryMovementReason::Correction => $this->auditCorrection($findings, $movement, $originals->get($movement->corrects_movement_id)),
                    InventoryMovementReason::CountAdjustment => $this->auditCountAdjustment($findings, $movement, $counts->get($movement->count_id)),
                    default => null,
                };
            }
        });

        foreach ($this->repeated('reservation_id', InventoryMovementReason::Sale) as $reservationId => $inventoryId) {
            $findings[] = $this->finding(self::DUPLICATE_SALE_EVIDENCE, $inventoryId, $reservationId, 'more than one sale movement for this reservation.');
        }

        foreach ($this->repeated('corrects_movement_id', InventoryMovementReason::Correction) as $movementId => $inventoryId) {
            $findings[] = $this->finding(self::CORRECTION_INVALID, $inventoryId, null, "movement #{$movementId} has more than one correction.");
        }

        foreach ($this->repeated('count_id', InventoryMovementReason::CountAdjustment) as $countId => $inventoryId) {
            $findings[] = $this->finding(self::COUNT_ADJUSTMENT_INVALID, $inventoryId, null, "count #{$countId} has more than one adjustment.");
        }
    }

    /** @return array<int, int> correlation id => inventory id, for correlations evidenced more than once */
    private function repeated(string $column, InventoryMovementReason $reason): array
    {
        return InventoryMovement::query()->toBase()
            ->where('reason', $reason->value)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw("{$column} as correlation, MIN(inventory_id) as inventory_id")
            ->pluck('inventory_id', 'correlation')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditMovementStamp(array &$findings, object $movement, ?string $baselineKind): void
    {
        $id = (int) $movement->id;
        $afterBaseline = (bool) $movement->after_baseline;

        $problem = match (true) {
            $afterBaseline && $baselineKind === null => 'stamped after a baseline that does not exist.',
            ! $afterBaseline && $baselineKind === InventoryBaselineKind::Genesis->value => 'stamped before the baseline of an Inventory that was born with one.',
            ! $afterBaseline && $movement->reason !== InventoryMovementReason::Sale->value => 'stamped before a baseline, which only a sale can be.',
            default => null,
        };

        if ($problem !== null) {
            $findings[] = $this->finding(self::MOVEMENT_BASELINE_STAMP_INVALID, (int) $movement->inventory_id, null, "movement #{$id} is {$problem}");
        }
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditMovementActor(array &$findings, object $movement): void
    {
        $isSale = $movement->reason === InventoryMovementReason::Sale->value;

        $valid = $isSale
            ? $movement->actor_type === InventoryActorType::System->value && $movement->actor_user_id === null
            : $movement->actor_type === InventoryActorType::StoreOwner->value && $movement->actor_user_id !== null;

        if (! $valid) {
            $findings[] = $this->finding(self::MOVEMENT_ACTOR_INVALID, (int) $movement->inventory_id, null, "movement #{$movement->id} has an actor inconsistent with its reason.");
        }
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditSale(array &$findings, object $movement, ?object $reservation): void
    {
        $inventoryId = (int) $movement->inventory_id;
        $reservationId = (int) $movement->reservation_id;

        if ($reservation === null || $reservation->status !== InventoryReservationStatus::Committed->value) {
            $findings[] = $this->finding(self::SALE_RESERVATION_NOT_COMMITTED, $inventoryId, $reservationId, "sale movement #{$movement->id} evidences a reservation that is not committed.");

            return;
        }

        if ((int) $movement->quantity_delta !== -(int) $reservation->quantity || (int) $reservation->inventory_id !== $inventoryId) {
            $findings[] = $this->finding(self::SALE_MISMATCH, $inventoryId, $reservationId, "sale movement #{$movement->id} disagrees with the quantity or Inventory of its reservation.");
        }
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditCorrection(array &$findings, object $movement, ?object $original): void
    {
        $inventoryId = (int) $movement->inventory_id;

        $valid = $original !== null
            && (int) $original->inventory_id === $inventoryId
            && (InventoryMovementReason::tryFrom((string) $original->reason)?->isCorrectable() ?? false)
            && (int) $movement->quantity_delta === -(int) $original->quantity_delta;

        if (! $valid) {
            $findings[] = $this->finding(self::CORRECTION_INVALID, $inventoryId, null, "correction #{$movement->id} is not the exact reversal of a correctable movement of its Inventory.");
        }
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditCountAdjustment(array &$findings, object $movement, ?object $count): void
    {
        $inventoryId = (int) $movement->inventory_id;

        $valid = $count !== null
            && (int) $count->inventory_id === $inventoryId
            && $count->status === InventoryCountStatus::Applied->value
            && (int) $movement->quantity_delta === (int) $count->observed_quantity - (int) $count->on_hand_at_submission;

        if (! $valid) {
            $findings[] = $this->finding(self::COUNT_ADJUSTMENT_INVALID, $inventoryId, null, "adjustment #{$movement->id} disagrees with its count.");
        }
    }

    /** @param  list<array<string, mixed>>  $findings */
    private function auditCounts(array &$findings): void
    {
        InventoryCount::query()->toBase()->chunkById($this->chunkSize, function (Collection $counts) use (&$findings) {
            $adjusted = InventoryMovement::query()->toBase()->whereIn('count_id', $counts->pluck('id')->all())->pluck('count_id')->flip();
            $resolvers = InventoryCount::query()->toBase()->whereIn('id', $counts->pluck('resolved_by_count_id')->filter()->all())->get(['id', 'inventory_id', 'status'])->keyBy('id');

            foreach ($counts as $count) {
                $inventoryId = (int) $count->inventory_id;

                if ($count->status === InventoryCountStatus::Applied->value
                    && (int) $count->observed_quantity !== (int) $count->on_hand_at_submission
                    && ! $adjusted->has($count->id)) {
                    $findings[] = $this->finding(self::COUNT_ADJUSTMENT_INVALID, $inventoryId, null, "applied count #{$count->id} has a variance but no adjustment.");
                }

                if ($count->status === InventoryCountStatus::DiscrepancyResolved->value) {
                    $resolver = $resolvers->get($count->resolved_by_count_id);

                    if ($resolver === null || $resolver->status !== InventoryCountStatus::Applied->value || (int) $resolver->inventory_id !== $inventoryId) {
                        $findings[] = $this->finding(self::COUNT_RESOLUTION_INVALID, $inventoryId, null, "count #{$count->id} was not resolved by an applied count of its Inventory.");
                    }
                }
            }
        });
    }

    /**
     * Genesis Inventories only: every commit of one post-dates the sale evidence.
     * For an adopted Inventory a commit may pre-date it, and the equation covers
     * the rest.
     *
     * @param  list<array<string, mixed>>  $findings
     */
    private function auditCommittedWithoutSale(array &$findings): void
    {
        InventoryReservation::query()->toBase()
            ->where('status', InventoryReservationStatus::Committed->value)
            ->whereIn('inventory_id', fn ($query) => $query->select('inventory_id')->from('inventory_baselines')->where('kind', InventoryBaselineKind::Genesis->value))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('inventory_movements')->whereColumn('inventory_movements.reservation_id', 'inventory_reservations.id'))
            ->chunkById($this->chunkSize, function (Collection $reservations) use (&$findings) {
                foreach ($reservations as $reservation) {
                    $findings[] = $this->finding(self::COMMITTED_RESERVATION_WITHOUT_SALE, (int) $reservation->inventory_id, (int) $reservation->id, 'a committed reservation of a genesis Inventory has no sale movement.');
                }
            }, 'inventory_reservations.id', 'id');
    }

    /** @return array{code: string, inventory_id: int|null, reservation_id: int|null, detail: string} */
    private function finding(string $code, ?int $inventoryId, ?int $reservationId, string $detail): array
    {
        return ['code' => $code, 'inventory_id' => $inventoryId, 'reservation_id' => $reservationId, 'detail' => $detail];
    }
}
