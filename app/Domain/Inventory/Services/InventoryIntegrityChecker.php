<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\InventoryReservationStatus;
use App\Domain\Inventory\Models\Inventory;
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
 * - it cannot verify `on_hand_quantity` against history: there is no stock
 *   ledger (no receipts, adjustments or initial-quantity record), so physical
 *   stock is taken as authoritative. That is a stated limitation, not a check
 *   that passes silently.
 *
 * Bounded: both passes walk the tables with `chunkById()`; nothing loads a
 * whole table.
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

        return $findings;
    }

    /** @return array{code: string, inventory_id: int|null, reservation_id: int|null, detail: string} */
    private function finding(string $code, ?int $inventoryId, ?int $reservationId, string $detail): array
    {
        return ['code' => $code, 'inventory_id' => $inventoryId, 'reservation_id' => $reservationId, 'detail' => $detail];
    }
}
