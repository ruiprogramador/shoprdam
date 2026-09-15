<?php

namespace App\Domain\Payouts\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Domain\Payouts\Services\PayoutService::abandon() when the
 * Payout's current attempt is Pending, Claimed, or NeedsAttention — i.e. its
 * real-world outcome at the provider is still unknown. Releasing the
 * reservation here would risk a double-spend: the provider may yet execute
 * the transfer for real after the reservation was already credited back
 * locally. Abandoning a Payout is only ever safe once every attempt tried so
 * far has come back definitively Failed, or none was ever started.
 */
class PayoutHasUnresolvedAttemptException extends RuntimeException {}
