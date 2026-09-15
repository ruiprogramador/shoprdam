<?php

namespace App\Domain\Payouts\Exceptions;

use RuntimeException;

/** Thrown when trying to start a new PayoutAttempt for a Payout that is already terminal (Succeeded/Cancelled/Failed). */
class PayoutAlreadyResolvedException extends RuntimeException {}
