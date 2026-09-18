<?php

namespace App\Domain\Orders\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emitted by App\Domain\Orders\Services\OrderLifecycleService exactly once
 * per Order transition that actually changed state — never for an
 * idempotent no-op, never for a refused/rolled-back transition.
 *
 * Dispatched only AFTER the outermost database transaction commits
 * (`DB::afterCommit`), so a listener can never observe — or act on — a
 * transition that later rolls back (e.g. the surrounding settlement
 * transaction in PaymentEventProcessor failing after the Order transition).
 *
 * Carries scalar IDs/slugs only, never a mutable model: a listener re-reads
 * current state itself. Listeners must be idempotent (a queued listener may
 * be retried) and must never call back into a financial path — this event
 * is a notification of a lifecycle fact, not a command, and is not a way
 * around the canonical transition or settlement boundaries.
 *
 * At-most-once, NOT durable delivery: the transition commits first, and if
 * the process dies before the after-commit callback runs the event is lost
 * (there is no outbox). A synchronous listener that throws does so after the
 * durable commit — the exception reaches the caller but nothing is rolled
 * back. Never rely on this event to make anything happen; re-derive from the
 * Order's persisted state instead.
 */
final class OrderTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $from,
        public readonly string $to,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
