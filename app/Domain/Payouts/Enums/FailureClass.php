<?php

namespace App\Domain\Payouts\Enums;

/**
 * Whether retrying a failed provider call stands any chance of succeeding —
 * see App\Domain\Payouts\Contracts\PayoutProviderContract::classifyFailure().
 * Deliberately its own copy of App\Domain\Payments\Enums\FailureClass rather
 * than a shared import: the two domains are kept independent of each other
 * on purpose (see tests/Architecture/PaymentsDomainBoundaryTest for the
 * equivalent boundary on the payments side) — a three-case enum is cheap to
 * duplicate, coupling two otherwise-unrelated domains for it is not.
 */
enum FailureClass
{
    case Retryable;
    case NonRetryable;
}
