<?php

namespace App\Payments\EasyPay\Exceptions;

use RuntimeException;

/**
 * The EasyPay API responded, but with a non-2xx status — as distinct from
 * EasyPayConnectionException, where no response came back at all. Carries
 * the HTTP status and decoded body so EasyPayPaymentProvider::classifyFailure()
 * can tell a transient failure (429/409/5xx — safe to retry under the same
 * Idempotency-Key) from a definitive rejection (400/403/404/422 — will fail
 * identically on every retry), per EasyPay's own documented error codes
 * (see docs.easypay.pt/docs/error-handling).
 */
class EasyPayRequestException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        string $message,
    ) {
        parent::__construct($message);
    }
}
