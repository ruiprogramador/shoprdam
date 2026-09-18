<?php

namespace App\Domain\Orders\DTOs;

use App\Domain\Orders\Enums\OrderLifecycleState;

/**
 * What an OrderLifecycleService transition did. `changed === false` is the
 * explicit, deliberate idempotent no-op (the Order was already in the target
 * state) — never an error, never a second application: no timestamp was
 * rewritten and no event was emitted.
 */
final readonly class OrderTransitionResult
{
    public function __construct(
        public OrderLifecycleState $from,
        public OrderLifecycleState $to,
        public bool $changed,
    ) {}
}
