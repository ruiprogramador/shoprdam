<?php

namespace App\Domain\Payouts\Exceptions;

use RuntimeException;

/** Thrown when a store has no wallet in the requested currency — a payout can never invent one. */
class PayoutWalletNotFoundException extends RuntimeException {}
