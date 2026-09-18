<?php

namespace App\Domain\Orders\Enums;

use App\Domain\Orders\Exceptions\InvalidOrderTransitionException;

/**
 * The formal vocabulary of an Order's lifecycle — one case per row actually
 * seeded in `order_statuses` AND actually produced by production code today
 * (docs/financial/ORDER-LIFECYCLE.md §2). The backing value IS the
 * `order_statuses.slug`, which `database/seeders/OrderStatusSeeder` states
 * must never change in production; this enum is a typed view over those
 * rows, not a replacement for the table.
 *
 * Deliberately NOT a mirror of Payment/PaymentAttempt state. `Failed` here
 * means "the most recent payment attempt failed" — it is NOT terminal,
 * because a Payment whose attempts all failed stays payable
 * (`PaymentAttemptStatus::Failed::blocksNewAttempt() === false`), and a
 * later attempt succeeding legitimately moves the Order `failed -> paid`
 * (proven by tests/Feature/Payments/CrossProviderFailoverTest). Making it
 * terminal would contradict that established, tested behavior.
 *
 * No fulfillment (`accepted`/`preparing`/`ready`/`completed`) or
 * `cancelled` case exists: the product has no buyer, no order items, and no
 * fulfillment actor for those states to mean anything, and no canonical
 * refund-initiation path a cancellation of a paid Order could safely use.
 * They are decisions for a later branch, not states to invent here.
 */
enum OrderLifecycleState: string
{
    /** Awaiting payment. The initial state. */
    case Pending = 'pending';

    /** A `sale` Wallet transaction for this Order is `completed`. Left only by a full refund. */
    case Paid = 'paid';

    /** The most recent payment attempt failed. Non-terminal: the Order is still payable. */
    case Failed = 'failed';

    /** A full `customer_refund` reversal of the Order's completed sale exists. Terminal. */
    case Refunded = 'refunded';

    /**
     * The transition matrix — the single definition of which state may
     * follow which (same-state repeats are handled separately as explicit
     * idempotent no-ops, see OrderLifecycleService).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Failed],
            self::Failed => [self::Paid],
            self::Paid => [self::Refunded],
            self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Strict: an `order_statuses` slug this enum doesn't know (e.g. a status
     * added by a future release before this code learns about it) fails
     * closed instead of being coerced into some known state.
     */
    public static function fromSlug(?string $slug): self
    {
        return self::tryFrom((string) $slug)
            ?? throw InvalidOrderTransitionException::unknownState($slug);
    }
}
