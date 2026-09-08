<?php

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Domain\Payments\Services\PaymentsHealthCheck when a
 * payments-domain config value (a health threshold, the provider event
 * retention period) fails App\Domain\Payments\ConfigInteger::parse() — never
 * caught internally to fall back to a coerced default. A misleading health
 * report (one computed against a silently-zeroed threshold) is worse than
 * no report at all, so `php artisan payments:health` lets this propagate
 * into an explicit failure instead of ever printing HEALTHY/NEEDS ATTENTION
 * against a wrong number.
 */
class InvalidPaymentsConfigException extends RuntimeException {}
