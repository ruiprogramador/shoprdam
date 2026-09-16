<?php

use App\Models\Store;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Support\Facades\Artisan;

it('exits successfully and reports consistent when every wallet reconciles', function () {
    $store = Store::factory()->create();
    app(WalletTransactionService::class)->record(app(WalletService::class)->getWallet($store, 'EUR'), 'sale', '50.00');

    $this->artisan('wallet:audit')
        ->assertExitCode(0)
        ->expectsOutputToContain('CONSISTENT');
});

it('exits with failure and reports the mismatch when a wallet has drifted, without writing anything', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '80.00');
    $wallet->update(['balance' => '100.00']);

    $this->artisan('wallet:audit')
        ->assertExitCode(1)
        ->expectsOutputToContain('DRIFT DETECTED');

    expect($wallet->fresh()->balance)->toBe('100.00');
});

it('emits a machine-readable JSON report with --json', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '80.00');
    $wallet->update(['balance' => '100.00']);

    $exitCode = Artisan::call('wallet:audit', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1);

    expect($output['healthy'])->toBeFalse()
        ->and($output['mismatch_count'])->toBe(1)
        ->and($output['mismatches'][0]['expected_balance'])->toBe('80.00')
        ->and($output['mismatches'][0]['actual_balance'])->toBe('100.00');
});

it('scopes the audit to a single store via --store', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    app(WalletTransactionService::class)->record(app(WalletService::class)->getWallet($storeA, 'EUR'), 'sale', '10.00');
    $walletB = app(WalletService::class)->getWallet($storeB, 'EUR');
    app(WalletTransactionService::class)->record($walletB, 'sale', '80.00');
    $walletB->update(['balance' => '100.00']);

    $this->artisan('wallet:audit', ['--store' => $storeA->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('CONSISTENT');
});

it('rejects a non-numeric --store instead of silently auditing everything', function () {
    $this->artisan('wallet:audit', ['--store' => 'abc'])
        ->assertExitCode(2);
});

it('never creates, updates, or deletes any StoreWalletTransaction or wallet row when run against a drifted wallet', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $transaction = app(WalletTransactionService::class)->record($wallet, 'sale', '80.00');
    $wallet->update(['balance' => '100.00']);

    $beforeTransaction = $transaction->fresh()->toArray();
    $beforeWallet = $wallet->fresh()->toArray();

    $this->artisan('wallet:audit')->assertExitCode(1);

    expect($transaction->fresh()->toArray())->toBe($beforeTransaction)
        ->and($wallet->fresh()->toArray())->toBe($beforeWallet);
});
