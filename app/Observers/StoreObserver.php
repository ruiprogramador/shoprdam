<?php

namespace App\Observers;

use App\Models\Store;
use App\Wallet\Services\WalletService;
use Illuminate\Support\Str;

class StoreObserver
{
    public function __construct(private readonly WalletService $walletService)
    {
    }

    /**
     * Generate a unique slug before the store is created, if not already set.
     */
    public function creating(Store $store): void
    {
        if (blank($store->slug)) {
            $store->slug = $this->generateUniqueSlug($store->name);
        }
    }

    /**
     * Create the store's default wallet right after it's created.
     */
    public function created(Store $store): void
    {
        $this->walletService->createDefaultWallet($store);
    }

    private function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $count = 1;

        while (
            Store::withTrashed()
                ->where('slug', $slug)
                ->exists()
        ){
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }
}
