<?php

namespace Tests\Fakes;

use App\Domain\Payments\Contracts\PaymentProviderContract;
use App\Domain\Payments\DTOs\ProviderPaymentResult;
use App\Domain\Payments\Enums\FailureClass;
use App\Domain\Payments\Models\PaymentAttempt;
use Throwable;

/**
 * A minimal, non-Stripe PaymentProviderContract implementation for testing
 * App\Domain\Payments\Services\PaymentService's own gating/generalization
 * logic in isolation — proves the domain doesn't secretly assume Stripe.
 * Register it into a test via App\Domain\Payments\PaymentProviderManager::extend().
 */
class FakeTestPaymentProvider implements PaymentProviderContract
{
    /** @var int[] */
    public array $calledForAttemptIds = [];

    public function __construct(
        private readonly string $providerName,
        private readonly ?ProviderPaymentResult $result = null,
        private readonly ?Throwable $throws = null,
        private readonly FailureClass $failureClass = FailureClass::NonRetryable,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function createOrGetPayment(PaymentAttempt $attempt): ProviderPaymentResult
    {
        $this->calledForAttemptIds[] = $attempt->id;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->result;
    }

    public function classifyFailure(Throwable $e): FailureClass
    {
        return $this->failureClass;
    }
}
