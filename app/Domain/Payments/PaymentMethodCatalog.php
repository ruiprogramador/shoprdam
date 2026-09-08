<?php

namespace App\Domain\Payments;

use InvalidArgumentException;

/**
 * The provider-agnostic menu of payment methods a customer may choose from.
 * config('payments.methods') is the single place a method is wired to the
 * provider that actually settles it — this class exists so a checkout flow
 * asks "which methods exist" and "which provider backs this method"
 * through it, instead of every caller growing its own
 * if (method === 'card') use stripe; if (method === 'mbway') use easypay;
 * conditional. Deliberately just a config reader: no plugin/capability
 * framework beyond that, since nothing here yet needs one.
 */
class PaymentMethodCatalog
{
    /** @return array<string, array{provider: string, label: string}> */
    public function methods(): array
    {
        return config('payments.methods', []);
    }

    /**
     * Method => label pairs safe to show in a customer-facing UI. Never
     * includes the provider — see the class docblock.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(
            static fn (array $method) => $method['label'],
            $this->methods(),
        );
    }

    public function isSupported(string $method): bool
    {
        return array_key_exists($method, $this->methods());
    }

    /**
     * @throws InvalidArgumentException if $method isn't in the registry — callers must
     *                                   validate against isSupported() (or Rule::in(array_keys(...)))
     *                                   first; this never falls back to a default provider.
     */
    public function providerFor(string $method): string
    {
        return $this->methods()[$method]['provider']
            ?? throw new InvalidArgumentException("No provider configured for payment method '{$method}'.");
    }
}
