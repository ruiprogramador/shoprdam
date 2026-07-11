<?php

namespace App\Services\Wallet;

use App\Models\Store;
use App\Models\StoreWallet;
use Nnjeim\World\Models\Currency;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WalletService
{
    /**
     * Default currency code used when creating a store's first wallet.
     */
    protected string $defaultCurrencyCode = 'EUR';

    /**
     * Create the default wallet for a newly created store.
     */
    public function createDefaultWallet(Store $store): StoreWallet
    {
        return $this->createWallet($store, $this->resolveCurrencyId($this->defaultCurrencyCode));
    }

    /**
     * Create a wallet for a store in a specific currency.
     * Returns the existing wallet if one already exists for that currency.
     */
    public function createWallet(Store $store, int $currencyId): StoreWallet
    {
        return DB::transaction(function () use ($store, $currencyId) {
            return StoreWallet::firstOrCreate(
                [
                    'store_id'    => $store->id,
                    'currency_id' => $currencyId,
                ],
                [
                    'balance' => 0,
                ]
            );
        });
    }

    /**
     * Get a store's wallet in a specific currency, or null if it doesn't exist.
     */
    public function getWallet(Store $store, string $currencyCode): ?StoreWallet
    {
        return $store->wallets()
            ->whereHas('currency', fn ($q) => $q->where('code', $currencyCode))
            ->first();
    }

    /**
     * Get a store's wallet in a specific currency, creating it if it doesn't exist.
     */
    public function getOrCreateWallet(Store $store, string $currencyCode): StoreWallet
    {
        return $this->createWallet($store, $this->resolveCurrencyId($currencyCode));
    }

    private function resolveCurrencyId(string $code): int
    {
        return Currency::query()
            ->where('code', $code)
            ->value('id')
            ?? throw new RuntimeException("Currency [{$code}] not found.");
    }
}