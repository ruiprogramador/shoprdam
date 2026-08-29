<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Contracts\PaymentProviderContract;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves a PaymentProviderContract implementation by driver name (e.g.
 * 'stripe'). Deliberately knows nothing about any concrete provider itself
 * — App\Providers\PaymentServiceProvider registers each driver via
 * extend(), reading the provider -> class mapping from config('payments.providers'),
 * so adding a provider never requires touching this class.
 */
class PaymentProviderManager extends Manager
{
    /**
     * No default: every caller resolves a provider explicitly by the name
     * stored on the PaymentAttempt row, never implicitly.
     */
    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException(
            'No default payment provider — resolve one explicitly via driver($name), e.g. from a PaymentAttempt\'s own provider column.'
        );
    }

    public function driver($driver = null): PaymentProviderContract
    {
        return parent::driver($driver);
    }
}
