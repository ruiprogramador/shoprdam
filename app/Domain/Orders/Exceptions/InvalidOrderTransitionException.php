<?php

namespace App\Domain\Orders\Exceptions;

use App\Domain\Orders\Enums\OrderLifecycleState;
use RuntimeException;

/**
 * A requested Order transition was refused — either the matrix forbids it,
 * the financial evidence backing it is missing/wrong, or the Order's current
 * state isn't one this code knows. Always fail-closed: nothing was written.
 */
class InvalidOrderTransitionException extends RuntimeException
{
    public static function forbidden(OrderLifecycleState $from, OrderLifecycleState $to): self
    {
        return new self("Order cannot transition from '{$from->value}' to '{$to->value}'.");
    }

    public static function unknownState(?string $slug): self
    {
        return new self("Order is in an unrecognized state ('".($slug ?? 'null')."'); refusing to transition it.");
    }

    public static function insufficientEvidence(OrderLifecycleState $target, string $reason): self
    {
        return new self("Order cannot become '{$target->value}': {$reason}");
    }
}
