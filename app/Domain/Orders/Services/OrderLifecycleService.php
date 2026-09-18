<?php

namespace App\Domain\Orders\Services;

use App\Domain\Orders\DTOs\OrderTransitionResult;
use App\Domain\Orders\Enums\OrderLifecycleState;
use App\Domain\Orders\Events\OrderTransitioned;
use App\Domain\Orders\Exceptions\InvalidOrderTransitionException;
use App\Domain\Orders\Exceptions\OrderTransitionConflictException;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\StoreWalletTransaction;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The single canonical boundary through which an Order's status changes —
 * see docs/financial/ORDER-LIFECYCLE.md. Nothing else in `app/` may write
 * `orders.order_status_id` (enforced mechanically by
 * tests/Architecture/OrderLifecycleBoundaryTest, plus a runtime guard on
 * App\Models\Order that rejects an Eloquent save changing it).
 *
 * ## Direction of authority
 *
 * Every transition here is authorized by a *settled financial fact*, never
 * the other way round. Each method takes the Order's own `sale` Wallet
 * transaction as evidence and re-reads it from the database (never trusting
 * the in-memory instance, which a caller — like
 * PaymentEventProcessor::markSettled(), holding a copy loaded before its own
 * confirm()/markFailed() — may hold in a stale state):
 *
 * - markPaid()          requires that sale to be `completed`;
 * - markPaymentFailed() requires that sale to be `failed`;
 * - markRefunded()      requires that sale to be `completed` AND to have a
 *                       `completed` `customer_refund` reversal.
 *
 * Only WalletTransactionService can create/complete/fail/reverse those rows
 * (WalletLedgerSingleWriterTest), so arbitrary application code cannot
 * fabricate the evidence — an Order cannot become `paid` because someone
 * asked; only because the Wallet says the payment settled. This class only
 * ever READS Wallet state; it never writes it, never touches a Payment, a
 * PaymentAttempt, or a provider event (ORDER-04/ORDER-12).
 *
 * ## Deliberate API shape
 *
 * There is intentionally no `setStatus($anything)`. Each public method is a
 * named transition with its own prerequisites; the matrix itself lives in
 * OrderLifecycleState.
 *
 * ## Concurrency
 *
 * One transaction: `SELECT ... FOR UPDATE` on the Order row, re-read the
 * *current* state under that lock (a stale `$order` instance is never used
 * to decide), validate, then a compare-and-set
 * `UPDATE ... WHERE order_status_id = <state we validated against>`. The lock
 * serializes concurrent transitions on MySQL/PostgreSQL; the CAS is the
 * portable backstop where the lock is a no-op (SQLite) and makes a lost race
 * fail closed (OrderTransitionConflictException) rather than overwrite.
 *
 * ## Idempotency
 *
 * Repeating a transition into the state the Order is already in is an
 * explicit no-op (`changed === false`): no timestamp rewrite, no event.
 * Anything else the matrix forbids throws InvalidOrderTransitionException.
 *
 * ## Events
 *
 * OrderTransitioned is dispatched via `DB::afterCommit`, i.e. only once the
 * outermost transaction commits — so if this runs inside
 * PaymentEventProcessor's settlement transaction and that later rolls back,
 * no event is ever emitted for a transition that didn't survive.
 *
 * It is a notification, not durable delivery: at-most-once. The commit is
 * durable first; if the process dies between that commit and the callback,
 * the event is simply lost (there is no outbox). And a *synchronous*
 * listener that throws does so AFTER the commit — the exception surfaces to
 * the caller, but nothing is rolled back (Laravel runs the callbacks outside
 * the commit's try/catch). Listeners must therefore be idempotent, should be
 * queued, and must never be relied on to make a transition happen.
 */
class OrderLifecycleService
{
    /** pending|failed -> paid, backed by a completed `sale` for this Order. */
    public function markPaid(Order $order, StoreWalletTransaction $sale): OrderTransitionResult
    {
        return $this->transition($order, OrderLifecycleState::Paid, function (Order $locked) use ($sale) {
            $this->assertOwnSale($locked, $sale, 'completed', OrderLifecycleState::Paid);
        });
    }

    /**
     * pending -> failed, backed by a `failed` `sale` for this Order. This is
     * "the latest payment attempt failed", NOT a terminal verdict on the
     * Order — see OrderLifecycleState::Failed.
     */
    public function markPaymentFailed(Order $order, StoreWalletTransaction $sale): OrderTransitionResult
    {
        return $this->transition($order, OrderLifecycleState::Failed, function (Order $locked) use ($sale) {
            $this->assertOwnSale($locked, $sale, 'failed', OrderLifecycleState::Failed);
        });
    }

    /** paid -> refunded, backed by a completed `sale` that has a completed full `customer_refund` reversal. */
    public function markRefunded(Order $order, StoreWalletTransaction $sale): OrderTransitionResult
    {
        return $this->transition($order, OrderLifecycleState::Refunded, function (Order $locked) use ($sale) {
            $fresh = $this->assertOwnSale($locked, $sale, 'completed', OrderLifecycleState::Refunded);

            $hasCompletedRefund = $fresh->childTransactions()
                ->whereHas('category', fn ($q) => $q->where('slug', 'customer_refund'))
                ->whereHas('status', fn ($q) => $q->where('slug', 'completed'))
                ->exists();

            if (! $hasCompletedRefund) {
                throw InvalidOrderTransitionException::insufficientEvidence(
                    OrderLifecycleState::Refunded,
                    "sale transaction #{$fresh->id} has no completed customer_refund reversal.",
                );
            }
        });
    }

    /** @param  Closure(Order): void  $verifyEvidence */
    private function transition(Order $order, OrderLifecycleState $target, Closure $verifyEvidence): OrderTransitionResult
    {
        return DB::transaction(function () use ($order, $target, $verifyEvidence) {
            // Re-read under lock — the passed-in $order may be arbitrarily stale.
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $locked->load('status');

            $current = OrderLifecycleState::fromSlug($locked->status?->slug);

            $verifyEvidence($locked);

            if ($current === $target) {
                return new OrderTransitionResult($current, $target, changed: false);
            }

            if (! $current->canTransitionTo($target)) {
                throw InvalidOrderTransitionException::forbidden($current, $target);
            }

            $now = CarbonImmutable::now();

            $changes = ['order_status_id' => OrderStatus::bySlugOrFail($target->value)->id];

            if ($target === OrderLifecycleState::Paid) {
                $changes['paid_at'] = $now;
            }

            if ($target === OrderLifecycleState::Refunded) {
                $changes['refunded_at'] = $now;
            }

            // Compare-and-set against the exact state validated above. A query
            // builder UPDATE (not $order->save()) — the only status write in
            // the codebase, and the one App\Models\Order's performUpdate()
            // guard never sees, because it doesn't go through a model instance.
            $affected = Order::query()
                ->whereKey($locked->getKey())
                ->where('order_status_id', $locked->order_status_id)
                ->update($changes);

            if ($affected !== 1) {
                throw OrderTransitionConflictException::forOrder($locked->getKey());
            }

            $orderId = (int) $locked->getKey();

            DB::afterCommit(fn () => event(new OrderTransitioned($orderId, $current->value, $target->value, $now)));

            return new OrderTransitionResult($current, $target, changed: true);
        });
    }

    /**
     * Re-reads the evidence row and verifies it is genuinely this Order's own
     * original `sale` in the required status. Returns the fresh row.
     */
    private function assertOwnSale(Order $order, StoreWalletTransaction $evidence, string $requiredStatusSlug, OrderLifecycleState $target): StoreWalletTransaction
    {
        $sale = StoreWalletTransaction::query()
            ->with(['status', 'category'])
            ->find($evidence->getKey());

        $reject = fn (string $reason) => throw InvalidOrderTransitionException::insufficientEvidence($target, $reason);

        if ($sale === null) {
            $reject('the evidence transaction does not exist.');
        }

        if ($sale->category?->slug !== 'sale' || $sale->isReversal()) {
            $reject("transaction #{$sale->id} is not an original sale.");
        }

        if (! $this->isReferencedBy($sale, $order)) {
            $reject("transaction #{$sale->id} does not belong to Order #{$order->getKey()}.");
        }

        if ($sale->status?->slug !== $requiredStatusSlug) {
            $reject("sale transaction #{$sale->id} is '{$sale->status?->slug}', expected '{$requiredStatusSlug}'.");
        }

        return $sale;
    }

    /**
     * Morph-aware ownership: whether this sale's polymorphic `referenceable`
     * IS this Order. Resolves the reference the way Eloquent itself does —
     * honoring a morph map if one is ever configured — and compares model
     * identity, never the raw stored `referenceable_type` string. That string
     * can legitimately be either a class name (every historical row) or a
     * morph alias (rows written after a map exists), and comparing it to the
     * Order's *current* morph class would refuse a perfectly valid settlement
     * the moment the two encodings diverge. Historical financial rows are
     * never rewritten to make this work.
     *
     * A type that doesn't resolve to the Order class at all (corrupt or
     * foreign) is rejected up front, so it fails closed as an ordinary
     * refused transition instead of an Eloquent "class not found" error.
     */
    private function isReferencedBy(StoreWalletTransaction $sale, Order $order): bool
    {
        $type = $sale->referenceable_type;

        if (! is_string($type) || $type === '') {
            return false;
        }

        if (! is_a(Relation::getMorphedModel($type) ?? $type, Order::class, true)) {
            return false;
        }

        return $order->is($sale->referenceable);
    }
}
