<?php

namespace App\Payouts\Testing;

use App\Domain\Payouts\Contracts\PayoutProviderContract;
use App\Domain\Payouts\DTOs\ProviderTransferResult;
use App\Domain\Payouts\Enums\FailureClass;
use App\Domain\Payouts\Models\PayoutAttempt;
use Throwable;

/**
 * A deterministic, in-memory PayoutProviderContract implementation used
 * exclusively by tests to exercise PayoutService/PayoutEventProcessor
 * without depending on ManualPayoutProvider's specific (deliberately
 * human-driven) semantics. Never registered in config/payouts.php — wired
 * into App\Domain\Payouts\PayoutProviderManager directly by tests via
 * `extend('fake', ...)`.
 */
class FakePayoutProvider implements PayoutProviderContract
{
    /** @var array<string, ProviderTransferResult> keyed by idempotency_key */
    private array $transfersByIdempotencyKey = [];

    private ?Throwable $nextException = null;

    private FailureClass $nextFailureClass = FailureClass::Retryable;

    public int $createTransferCalls = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function createTransfer(PayoutAttempt $attempt): ProviderTransferResult
    {
        $this->createTransferCalls++;

        if ($this->nextException !== null) {
            $exception = $this->nextException;
            $this->nextException = null;

            throw $exception;
        }

        return $this->transfersByIdempotencyKey[$attempt->idempotency_key] ??= new ProviderTransferResult(
            providerReference: 'fake-ref-'.$attempt->idempotency_key,
            amount: $attempt->payout->amount,
            currency: $attempt->payout->currency->code,
            correlationId: (string) $attempt->payout_id,
        );
    }

    public function classifyFailure(Throwable $e): FailureClass
    {
        return $this->nextFailureClass;
    }

    public function willThrow(Throwable $e, FailureClass $classifiedAs = FailureClass::Retryable): void
    {
        $this->nextException = $e;
        $this->nextFailureClass = $classifiedAs;
    }

    /** Forces a specific result for the next createTransfer() call, bypassing the deterministic default. */
    public function willReturn(PayoutAttempt $attempt, ProviderTransferResult $result): void
    {
        $this->transfersByIdempotencyKey[$attempt->idempotency_key] = $result;
    }
}
