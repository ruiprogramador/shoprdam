<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\InventoryReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Exceptions\InvalidReservationException;
use App\Domain\Inventory\Exceptions\InventoryIntegrityException;
use App\Domain\Inventory\Models\Inventory;
use App\Domain\Inventory\Models\InventoryReservation;
use App\Domain\Orders\Services\OrderLineIntegrityChecker;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single canonical boundary through which stock is reserved, consumed or
 * given back — see docs/inventory/INVENTORY-RESERVATIONS.md. Nothing else in
 * `app/` may write `inventories` or `inventory_reservations` (enforced
 * mechanically by tests/Architecture/InventoryBoundaryTest, plus refusal of
 * every Eloquent write on both models).
 *
 * ## Vocabulary
 *
 *     available = on_hand_quantity - reserved_quantity        (derived, never stored)
 *
 *     reserve(q)  reserved += q                       iff available >= q
 *     release(q)  reserved -= q                       (on_hand untouched)
 *     commit(q)   reserved -= q AND on_hand -= q      (available untouched)
 *
 * `reserved_quantity` is always the sum of the `reserved` reservation rows of
 * that Inventory; it moves only in the same transaction, by the same
 * quantity, as the row's own status transition.
 *
 * ## The oversell guard is ONE statement
 *
 * Reserving is never "read available, compare in PHP, write". It is a single
 * conditional UPDATE — `SET reserved_quantity = reserved_quantity + q WHERE
 * id = ? AND on_hand_quantity - reserved_quantity >= q` — whose affected-row
 * count IS the answer. The database takes the row's write lock, re-evaluates
 * the predicate against the latest committed row, and applies the increment
 * as one atomic step, so of two concurrent reservations for the last unit one
 * finds the predicate false and changes nothing (InnoDB and PostgreSQL READ
 * COMMITTED both re-check the WHERE after waiting on the row lock; SQLite has
 * a single writer). No SELECT of a quantity ever decides anything. The CHECK
 * `reserved_quantity <= on_hand_quantity` is the database backstop.
 *
 * ## Terminal transitions are compare-and-set on the reservation row
 *
 * commit/release first run `UPDATE inventory_reservations SET status = ?
 * WHERE id = ? AND status = 'reserved'`. Exactly one concurrent caller sees
 * one affected row and only that caller then moves the counters, in the same
 * transaction. A loser sees zero, re-reads the row under a locking read and
 * either is a same-state idempotent no-op (duplicate commit/release) or is a
 * refused illegal transition (release after commit, commit after release).
 * Idempotency is therefore enforced by the row itself, never by the caller
 * or by provider-event idempotency.
 *
 * ## Order-level, all-or-nothing
 *
 * Every operation takes an Order and acts on all of its lines in ONE
 * transaction: a failure on any line rolls back every line's effect. Lines
 * are always processed in ascending (inventory id, order item id) order — a
 * fixed global lock order, so two multi-line operations can never wait on each
 * other in a cycle.
 *
 * ## Idempotent reserve
 *
 * `unique(order_item_id)` is the logical identity. A repeated reserve() finds
 * the Order's reservations and returns them with no further effect; if two
 * identical calls race, the loser's INSERT fails on that unique index, its
 * transaction rolls back (undoing any counter change), and it re-runs once and
 * then observes the winner's rows.
 *
 * ## What this class deliberately does NOT do
 *
 * - No Payment/Wallet/provider knowledge and no HTTP: transactions here are
 *   short, local and never span a provider call. Nothing calls commit/release
 *   today: PaymentAttempt failure and Order `failed` are NOT releases (a later
 *   attempt may still succeed), there is no cancellation, and automatic expiry
 *   is not implemented because releasing a reservation whose Payment can still
 *   succeed is an unresolved policy question (design document §12).
 * - No catch-and-compensate. An exception or timeout never increases
 *   availability: a failed transaction rolls back and leaves the stored state
 *   the source of truth; an unknown outcome is resolved by re-reading it (or by
 *   repeating the idempotent call), never by guessing.
 * - No Product price/currency: quantities come from the OrderItem snapshot only.
 * - No stock creation or adjustment: there is no `setStock`.
 */
class InventoryReservationService
{
    /**
     * Bounds the retry loop below. The identity-violation race (reserve()
     * only) always resolves on the first retry — the winner has, by
     * definition, already committed by the time this transaction's INSERT
     * fails against it — so the bound is inert there, a safety cap only. The
     * MariaDB snapshot conflict (see isMariadbSnapshotConflict()) is a real
     * N-way race: under the 4-way barrier scenario in
     * tests/Concurrency/InventoryMariadbConcurrencyTest.php a loser can
     * collide again on a retry before winning or legitimately losing to
     * InsufficientStockException (a different, non-retried exception). 8
     * gives that scenario comfortable headroom while still failing closed —
     * see the class docblock's "No catch-and-compensate" — rather than
     * retrying indefinitely under pathological contention.
     */
    private const MAX_RETRY_ATTEMPTS = 8;

    public function __construct(
        private readonly OrderLineIntegrityChecker $orderLines = new OrderLineIntegrityChecker,
    ) {}

    /**
     * Holds every line's quantity for the Order, or nothing at all.
     *
     * Only a line-backed Order with at least one line is eligible: a legacy
     * line-less Order has no Product/quantity to reserve and none is ever
     * inferred. Returns the Order's reservations (ordered by inventory id,
     * then id); repeating the call on an already-reserved Order changes nothing.
     *
     * @return Collection<int, InventoryReservation>
     *
     * @throws InsufficientStockException if any line cannot be reserved (nothing is reserved)
     * @throws InvalidReservationException if the Order has no lines, a Product has no Inventory, or a line's reservation is already terminal
     * @throws InventoryIntegrityException if stored state contradicts itself
     */
    public function reserve(Order $order): Collection
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->reserveWithinTransaction($order));
            } catch (QueryException $e) {
                // A concurrent identical reserve() won the unique(order_item_id)
                // claim; this attempt rolled back completely — retry now
                // observes the winner's committed rows. Or: MariaDB's snapshot
                // conflict (see isMariadbSnapshotConflict()) on the Inventory
                // row lock — retry from a clean transaction, per its own
                // message. Anything else is a real error and propagates.
                if ($attempt >= self::MAX_RETRY_ATTEMPTS
                    || ! ($this->isReservationIdentityViolation($e) || $this->isMariadbSnapshotConflict($e))) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Consumes the stock of every reserved line: `reserved -> committed`,
     * `on_hand` and `reserved` both drop by the line's quantity. All lines or
     * none. Returns how many reservations actually changed — `0` for a
     * repeated call (every line already committed), which has no stock effect.
     *
     * @throws InvalidReservationException if the Order has no reservations or any line is released
     * @throws InventoryIntegrityException if stored state contradicts itself
     */
    public function commit(Order $order): int
    {
        return $this->settle($order, InventoryReservationStatus::Committed);
    }

    /**
     * Gives every reserved line's hold back: `reserved -> released`, only
     * `reserved_quantity` drops. All lines or none. Returns how many
     * reservations actually changed — `0` for a repeated call.
     *
     * Nothing in production calls this today; see the class docblock. Whoever
     * first does must first resolve what a provider success arriving after the
     * release means (design document §12).
     *
     * @throws InvalidReservationException if the Order has no reservations or any line is committed
     * @throws InventoryIntegrityException if stored state contradicts itself
     */
    public function release(Order $order): int
    {
        return $this->settle($order, InventoryReservationStatus::Released);
    }

    /** @return Collection<int, InventoryReservation> */
    private function reserveWithinTransaction(Order $order): Collection
    {
        $orderId = (int) $order->getKey();

        // Fail closed on a line-backed Order whose lines no longer add up to
        // its amount (a raw write tampered with a quantity/price). A legacy
        // line-less Order passes this check and is then refused below.
        $this->orderLines->assertConsistent($order);

        $items = OrderItem::query()->where('order_id', $orderId)->orderBy('id')->get(['id', 'product_id', 'quantity']);

        if ($items->isEmpty()) {
            throw InvalidReservationException::noLines($orderId);
        }

        $inventories = Inventory::query()
            ->whereIn('product_id', $items->pluck('product_id')->unique()->all())
            ->get(['id', 'product_id'])
            ->keyBy('product_id');

        $existing = InventoryReservation::query()
            ->whereIn('order_item_id', $items->pluck('id')->all())
            ->get()
            ->keyBy('order_item_id');

        if ($existing->isNotEmpty()) {
            return $this->existingReservations($orderId, $items, $existing, $inventories);
        }

        // Every line must have an Inventory before anything is written.
        foreach ($items as $item) {
            if (! $inventories->has($item->product_id)) {
                throw InvalidReservationException::inventoryMissing((int) $item->product_id);
            }
        }

        // Deterministic global lock order: (inventory id, order item id).
        $plan = $items
            ->sortBy(fn (OrderItem $item) => [(int) $inventories->get($item->product_id)->id, (int) $item->id])
            ->values();

        foreach ($plan as $item) {
            $inventoryId = (int) $inventories->get($item->product_id)->id;
            $quantity = (int) $item->quantity;
            $now = now();

            // Take the Inventory row's WRITE lock before inserting its child. The
            // reservation INSERT checks its foreign key with a SHARED lock on
            // this very row; two buyers that each hold that shared lock and then
            // ask for the exclusive one for the UPDATE below deadlock on InnoDB
            // (found by tests/Concurrency, invisible on SQLite). Locking first —
            // in the same ascending order as everything else — makes them queue
            // instead. This is lock acquisition only: the guard is still the
            // conditional UPDATE, and no quantity is read.
            Inventory::query()->whereKey($inventoryId)->lockForUpdate()->toBase()->value('id');

            // The unique(order_item_id) claim comes next, so a racing duplicate
            // fails here, before it can touch a counter.
            DB::table('inventory_reservations')->insert([
                'inventory_id' => $inventoryId,
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'status' => InventoryReservationStatus::Reserved->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // THE oversell guard: one atomic conditional UPDATE. See the class docblock.
            $affected = DB::table('inventories')
                ->where('id', $inventoryId)
                ->whereRaw('on_hand_quantity - reserved_quantity >= ?', [$quantity])
                ->update([
                    'reserved_quantity' => DB::raw('reserved_quantity + '.$quantity),
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                Log::info('Inventory reservation refused: insufficient available stock.', [
                    'order_id' => $orderId,
                    'product_id' => (int) $item->product_id,
                    'inventory_id' => $inventoryId,
                    'requested' => $quantity,
                ]);

                throw InsufficientStockException::forProduct((int) $item->product_id, $quantity);
            }
        }

        return $this->reservationsFor($items);
    }

    /**
     * Idempotent replay of reserve(): every line must already have exactly one
     * `reserved` reservation that still matches its OrderItem and Inventory.
     *
     * @param  Collection<int, OrderItem>  $items
     * @param  Collection<int, InventoryReservation>  $existing  keyed by order_item_id
     * @param  Collection<int, Inventory>  $inventories  keyed by product_id
     * @return Collection<int, InventoryReservation>
     */
    private function existingReservations(int $orderId, Collection $items, Collection $existing, Collection $inventories): Collection
    {
        if ($existing->count() !== $items->count()) {
            throw InventoryIntegrityException::partiallyReserved($orderId);
        }

        foreach ($items as $item) {
            /** @var InventoryReservation $reservation */
            $reservation = $existing->get($item->id);

            $this->assertMatchesSource($reservation, $item, $inventories->get($item->product_id));

            if ($reservation->status !== InventoryReservationStatus::Reserved) {
                throw InvalidReservationException::alreadyTerminal((int) $item->id, $reservation->status);
            }
        }

        return $this->reservationsFor($items);
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @return Collection<int, InventoryReservation>
     */
    private function reservationsFor(Collection $items): Collection
    {
        return InventoryReservation::query()
            ->whereIn('order_item_id', $items->pluck('id')->all())
            ->orderBy('inventory_id')
            ->orderBy('id')
            ->get();
    }

    private function settle(Order $order, InventoryReservationStatus $target): int
    {
        $orderId = (int) $order->getKey();

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($orderId, $target) {
                    $items = OrderItem::query()->where('order_id', $orderId)->get(['id', 'product_id', 'quantity'])->keyBy('id');

                    $reservations = InventoryReservation::query()
                        ->whereIn('order_item_id', $items->keys()->all())
                        ->orderBy('inventory_id')
                        ->orderBy('id')
                        ->get();

                    if ($reservations->isEmpty()) {
                        throw InvalidReservationException::noReservations($orderId);
                    }

                    if ($reservations->count() !== $items->count()) {
                        throw InventoryIntegrityException::partiallyReserved($orderId);
                    }

                    $inventories = Inventory::query()->whereIn('id', $reservations->pluck('inventory_id')->unique()->all())->get(['id', 'product_id'])->keyBy('id');

                    $changed = 0;

                    foreach ($reservations as $reservation) {
                        /** @var OrderItem|null $item */
                        $item = $items->get($reservation->order_item_id);

                        $this->assertMatchesSource($reservation, $item, $inventories->get($reservation->inventory_id));

                        if ($this->transition($reservation, $target)) {
                            $changed++;
                        }
                    }

                    return $changed;
                });
            } catch (QueryException $e) {
                // MariaDB snapshot conflict on the Inventory row lock taken by
                // transition() — same signal and remedy as reserve()'s, see
                // isMariadbSnapshotConflict(). Anything else is a real error.
                if ($attempt >= self::MAX_RETRY_ATTEMPTS || ! $this->isMariadbSnapshotConflict($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * One reservation's named transition. Returns whether THIS call performed
     * it (`false` = the reservation was already in the target state).
     */
    private function transition(InventoryReservation $reservation, InventoryReservationStatus $target): bool
    {
        $reservationId = (int) $reservation->getKey();
        $now = now();

        // Same rule as reserve(): the Inventory row's write lock comes BEFORE any
        // write to its child reservation. Changing `status` re-inserts the entry
        // of the (inventory_id, status) index — the foreign key's supporting
        // index — which makes InnoDB take a SHARED lock on this parent row; two
        // transactions each holding it and then asking for the exclusive lock
        // for the counter UPDATE deadlock (found by tests/Concurrency). One
        // hierarchy everywhere: Inventory row, then its reservation rows.
        Inventory::query()->whereKey($reservation->inventory_id)->lockForUpdate()->toBase()->value('id');

        // Compare-and-set: the single point at which a terminal transition is won.
        $won = DB::table('inventory_reservations')
            ->where('id', $reservationId)
            ->where('status', InventoryReservationStatus::Reserved->value)
            ->update([
                'status' => $target->value,
                $target === InventoryReservationStatus::Committed ? 'committed_at' : 'released_at' => $now,
                'updated_at' => $now,
            ]);

        if ($won === 1) {
            $this->moveCounters($reservation, $target, $now);

            return true;
        }

        // Lost (or the row is not `reserved`). A locking read is a *current*
        // read — under MySQL REPEATABLE READ a plain SELECT could still show
        // the pre-winner snapshot.
        // toBase(): the raw column, not the enum cast — an unknown value must be reportable.
        $raw = InventoryReservation::query()->whereKey($reservationId)->lockForUpdate()->toBase()->value('status');
        $current = InventoryReservationStatus::tryFrom((string) $raw);

        if ($current === null) {
            throw InventoryIntegrityException::unknownStatus($reservationId, $raw);
        }

        if ($current === $target) {
            return false;
        }

        throw InvalidReservationException::illegalTransition($reservationId, $current, $target);
    }

    /**
     * The counter half of a transition this transaction just won. The
     * predicates make a drifted counter refuse (rolling the whole transaction
     * back, CAS included) instead of going negative or absorbing a bad state.
     */
    private function moveCounters(InventoryReservation $reservation, InventoryReservationStatus $target, mixed $now): void
    {
        $quantity = (int) $reservation->quantity;
        $inventoryId = (int) $reservation->inventory_id;

        $query = DB::table('inventories')
            ->where('id', $inventoryId)
            ->where('reserved_quantity', '>=', $quantity);

        if ($target === InventoryReservationStatus::Committed) {
            $affected = $query
                ->where('on_hand_quantity', '>=', $quantity)
                ->update([
                    'on_hand_quantity' => DB::raw('on_hand_quantity - '.$quantity),
                    'reserved_quantity' => DB::raw('reserved_quantity - '.$quantity),
                    'updated_at' => $now,
                ]);
        } else {
            $affected = $query->update([
                'reserved_quantity' => DB::raw('reserved_quantity - '.$quantity),
                'updated_at' => $now,
            ]);
        }

        if ($affected !== 1) {
            Log::error('Inventory counters cannot absorb a reservation transition; rolled back.', [
                'reservation_id' => (int) $reservation->getKey(),
                'inventory_id' => $inventoryId,
                'target' => $target->value,
                'quantity' => $quantity,
            ]);

            throw InventoryIntegrityException::counterDrift((int) $reservation->getKey(), $inventoryId);
        }
    }

    /**
     * A reservation must still agree with the OrderItem it belongs to (same
     * quantity) and be held on the Inventory of that item's Product.
     */
    private function assertMatchesSource(InventoryReservation $reservation, ?OrderItem $item, ?Inventory $inventory): void
    {
        $id = (int) $reservation->getKey();

        $problem = match (true) {
            $item === null => 'its OrderItem no longer exists',
            (int) $reservation->quantity !== (int) $item->quantity => "quantity {$reservation->quantity} differs from the OrderItem's {$item->quantity}",
            $inventory === null,
            (int) $inventory->id !== (int) $reservation->inventory_id,
            (int) $inventory->product_id !== (int) $item->product_id => "it is not held on the Inventory of the item's Product (Inventory #{$reservation->inventory_id})",
            default => null,
        };

        if ($problem !== null) {
            Log::error('Inventory reservation disagrees with its source.', ['reservation_id' => $id, 'problem' => $problem]);

            throw InventoryIntegrityException::reservationMismatch($id, $problem);
        }
    }

    /**
     * Whether a database error is the unique(order_item_id) claim being lost
     * to a concurrent identical reserve() — never any other constraint (a FK
     * or CHECK violation is a real error and must propagate).
     */
    private function isReservationIdentityViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        $isUnique = $sqlState === '23505'
            || ($sqlState === '23000' && ($driverCode === 1062 || str_contains($message, 'UNIQUE constraint failed')));

        return $isUnique && str_contains($message, 'order_item_id');
    }

    /**
     * MariaDB ships with `innodb_snapshot_isolation=ON` (no such setting on
     * MySQL; PostgreSQL has no equivalent behavior either) — an optimistic
     * check on locking reads that makes `SELECT ... FOR UPDATE` (both
     * lockForUpdate() call sites above) fail with this exact error instead of
     * blocking on the row lock, when the row was written by a transaction
     * that committed after this one's snapshot was taken. Confirmed on real
     * MariaDB 11.8.9 (`tests/Concurrency/InventoryMariadbConcurrencyTest.php`)
     * — real MySQL 8.0.44 and PostgreSQL 16 do not exhibit it, confirmed on
     * the same operations. MariaDB's own message names the correct remedy —
     * restart the transaction — which is exactly what retrying
     * DB::transaction() from scratch does (never resuming the failed one).
     *
     * Matched narrowly on SQLSTATE + driver code + both fixed phrases of
     * MariaDB's own wording, not on driver/connection name alone: precise
     * enough that this can never swallow a real error (a genuine deadlock,
     * MySQL's own lock-wait-timeout, or anything else uses a different
     * SQLSTATE/code/message and is rethrown unchanged).
     */
    private function isMariadbSnapshotConflict(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        return $sqlState === 'HY000'
            && $driverCode === 1020
            && str_contains($message, 'Record has changed since last read')
            && str_contains($message, 'try restarting transaction');
    }
}
