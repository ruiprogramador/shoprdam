<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Contracts\ProviderEventTranslator;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves a ProviderEventTranslator by provider name — the counterpart to
 * PaymentProviderManager, kept as its own manager rather than overloaded
 * onto that one, since a provider's payment contract and its event
 * translator are different responsibilities that can (and for Stripe
 * today, do) live in different classes. Registered the same
 * config-driven way in App\Providers\PaymentServiceProvider.
 */
class ProviderEventTranslatorManager extends Manager
{
    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException(
            'No default provider event translator — resolve one explicitly via driver($name), e.g. from a PaymentProviderEvent\'s own provider column.'
        );
    }

    public function driver($driver = null): ProviderEventTranslator
    {
        return parent::driver($driver);
    }
}
