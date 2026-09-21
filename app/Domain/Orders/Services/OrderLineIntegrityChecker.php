<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\Exceptions\OrderLineIntegrityException;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * Read-only verifier that an Order's stored aggregate still equals what its
 * immutable OrderItem snapshots say (ORDER-ITEM-08). Used as a fail-closed
 * gate before a Payment financial effect
 * (App\Domain\Payments\Services\PaymentService) — it detects, it never
 * repairs: nothing is rewritten, and a mismatch is thrown, not fixed.
 *
 * Reads only `orders` and `order_items`. It never reads a Product — a
 * historical amount must never depend on current catalog state (ORDER-ITEM-14).
 *
 * ## Provenance comes from `orders.is_line_backed`, never from line existence
 *
 * The persistent flag (set once, by OrderCreationService, atomically with the
 * lines) — not "does it have lines right now" — decides which rule applies:
 *
 * - legacy (`false`) + zero lines  → allowed. Everything created before
 *   feat/order-items, plus factory/dev-tool Orders, has nothing to verify
 *   against and must not become unpayable merely because it predates this domain;
 * - line-backed + ≥ 1 line         → amount and currency are validated exactly;
 * - line-backed + zero lines       → corrupt (lines were lost after creation),
 *   thrown, NEVER treated as legacy;
 * - legacy + ≥ 1 line              → contradictory provenance, thrown.
 */
class OrderLineIntegrityChecker
{
    /**
     * Re-reads the Order's committed provenance/amount/currency and its lines
     * from the database (the passed instance may be stale) and compares them.
     *
     * @throws OrderLineIntegrityException
     */
    public function assertConsistent(Order $order): void
    {
        $stored = Order::query()->whereKey($order->getKey())->firstOrFail(['id', 'amount', 'currency_id', 'is_line_backed']);
        $orderId = (int) $stored->id;

        $items = OrderItem::query()->where('order_id', $orderId)->get();

        if (! $stored->is_line_backed) {
            if ($items->isNotEmpty()) {
                throw OrderLineIntegrityException::legacyWithLines($orderId);
            }

            return;
        }

        if ($items->isEmpty()) {
            throw OrderLineIntegrityException::lineBackedWithoutLines($orderId);
        }

        $sum = '0.00';

        foreach ($items as $item) {
            if ((int) $item->currency_id !== (int) $stored->currency_id) {
                throw OrderLineIntegrityException::currencyMismatch($orderId, (int) $item->id);
            }

            $sum = bcadd($sum, $item->lineTotal(), 2);
        }

        if (bccomp($sum, (string) $stored->amount, 2) !== 0) {
            throw OrderLineIntegrityException::amountMismatch($orderId, (string) $stored->amount, $sum);
        }
    }
}
