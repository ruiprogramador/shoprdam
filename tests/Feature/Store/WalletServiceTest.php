<?php

use App\Models\Store;
use App\Services\Wallet\WalletService;
use Nnjeim\World\Models\Currency;

it('returns the existing default wallet when one already exists', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    $existingWallet = $store->wallets()->first();

    $wallet = $walletService->createDefaultWallet($store);

    expect($wallet->id)
        ->toBe($existingWallet->id)
        ->and($wallet->currency->code)
        ->toBe('EUR')
        ->and($store->fresh()->wallets)
        ->toHaveCount(1);
});

it('creates a wallet in another currency', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    $usd = Currency::query()->firstWhere('code', 'USD');

    expect($usd)->not->toBeNull();

    $wallet = $walletService->createWallet($store, $usd->id);

    expect($wallet->store_id)
        ->toBe($store->id)
        ->and($wallet->currency->code)->toBe('USD')
        ->and($wallet->balance)->toBe('0.00')
        ->and($wallet->last_transaction_at)->toBeNull()
        ->and($store->fresh()->wallets)->toHaveCount(2);
});

it('returns an existing wallet by currency code', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    $wallet = $walletService->getWallet($store, 'EUR');

    expect($wallet)
        ->not->toBeNull()
        ->and($wallet->store_id)
        ->toBe($store->id)
        ->and($wallet->currency->code)
        ->toBe('EUR');
});

it('returns null when a wallet does not exist', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    expect(
        $walletService->getWallet($store, 'USD')
    )->toBeNull();
});

it('creates a missing wallet when using getOrCreateWallet', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    expect($store->wallets)
        ->toHaveCount(1);

    $wallet = $walletService->getOrCreateWallet($store, 'USD');

    expect($wallet->currency->code)
        ->toBe('USD')
        ->and($wallet->store_id)->toBe($store->id)
        ->and($wallet->balance)->toBe('0.00')
        ->and($wallet->last_transaction_at)->toBeNull()
        ->and($store->fresh()->wallets)
        ->toHaveCount(2);
});

it('returns the existing wallet when getOrCreateWallet is called twice', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    $wallet1 = $walletService->getOrCreateWallet($store, 'EUR');
    $wallet2 = $walletService->getOrCreateWallet($store, 'EUR');

    expect($wallet1->id)
        ->toBe($wallet2->id)
        ->and($store->fresh()->wallets)
        ->toHaveCount(1);
});

it('throws an exception for an unknown currency', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    expect(fn () => $walletService->getOrCreateWallet($store, 'XXX'))
        ->toThrow(RuntimeException::class);
});

it('does not create duplicate wallet for the same currency', function () {
    $walletService = app(WalletService::class);

    $store = Store::factory()->create();

    $eur = Currency::query()->firstWhere('code', 'EUR');

    expect($eur)->not->toBeNull();

    $wallet1 = $walletService->createWallet($store, $eur->id);
    $wallet2 = $walletService->createWallet($store, $eur->id);

    expect($wallet1->id)
        ->toBe($wallet2->id)
        ->and($store->fresh()->wallets)
        ->toHaveCount(1);
});