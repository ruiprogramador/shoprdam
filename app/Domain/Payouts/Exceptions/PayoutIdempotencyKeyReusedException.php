<?php

namespace App\Domain\Payouts\Exceptions;

use RuntimeException;

/**
 * Thrown when a request replays a (store, idempotency_key) pair that
 * already names an existing Payout, but with different parameters (a
 * different wallet or amount) — see
 * App\Domain\Payouts\Services\PayoutService::request(). Fails closed rather
 * than silently reusing the key for a different intent: an idempotency key
 * is a promise that repeating it replays the *same* request, never a way to
 * mutate an existing reservation.
 */
class PayoutIdempotencyKeyReusedException extends RuntimeException {}
