<?php

use App\Models\Store;
use App\Services\Wallet\WalletService;

/**
 * The premise LEDGER-03 rests on: every StoreWallet in production is
 * created with a zero opening balance, so `expected balance = Σcredits −
 * Σdebits` never needs an "opening balance" term. This is what makes that
 * formula correct without qualification — see
 * App\Services\Wallet\WalletLedgerAuditor's own docblock.
 */
it('creates a store\'s default wallet at a zero opening balance via the store observer', function () {
    $store = Store::factory()->create();

    $wallet = $store->wallets()->first();

    expect($wallet)->not->toBeNull()
        ->and($wallet->balance)->toBe('0.00');
});

it('creates a wallet in a new currency at a zero opening balance via getOrCreateWallet', function () {
    $store = Store::factory()->create();

    $wallet = app(WalletService::class)->getOrCreateWallet($store, 'USD');

    expect($wallet->balance)->toBe('0.00');
});

it('never resets an existing wallet\'s balance when getOrCreateWallet is called again for the same currency', function () {
    $store = Store::factory()->create();
    $wallet = $store->wallets()->first();
    $wallet->update(['balance' => '42.50']);

    $again = app(WalletService::class)->getOrCreateWallet($store, $wallet->currency->code);

    expect($again->id)->toBe($wallet->id)
        ->and($again->balance)->toBe('42.50');
});

it('confirms WalletService::createWallet always hardcodes a zero opening balance in its create-defaults', function () {
    $source = file_get_contents(app_path('Services/Wallet/WalletService.php'));

    expect($source)->toContain("'balance' => '0.00'");
});
