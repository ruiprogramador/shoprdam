<?php

use App\Domain\Payouts\Enums\PayoutStatus;
use App\Domain\Payouts\Services\PayoutService;
use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Models\Store;
use App\Services\Wallet\WalletLedgerAuditor;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;

/**
 * Real-flow LEDGER invariant tests for the Payout path — via
 * App\Domain\Payouts\Services\PayoutService, the actual production entry
 * point, then WalletLedgerAuditor confirming zero drift.
 */
it('LEDGER-05: a payout debit reconciles exactly and never exceeds the available balance', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '100.00');

    app(PayoutService::class)->request($store, 'EUR', '80.00', 'ledger-payout-key-1');

    $result = app(WalletLedgerAuditor::class)->auditWallet($wallet->fresh());

    expect($wallet->fresh()->balance)->toBe('20.00')
        ->and($result->expectedBalance)->toBe('20.00')
        ->and($result->isConsistent())->toBeTrue();
});

it('LEDGER-05: a request exceeding the available balance never posts a partial debit — zero drift either way', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '50.00');

    try {
        app(PayoutService::class)->request($store, 'EUR', '80.00', 'ledger-payout-key-2');
    } catch (InsufficientWalletBalanceException) {
        // expected — see PayoutServiceRequestTest for the dedicated invariant test.
    }

    $result = app(WalletLedgerAuditor::class)->auditWallet($wallet->fresh());

    expect($wallet->fresh()->balance)->toBe('50.00')
        ->and($result->expectedBalance)->toBe('50.00')
        ->and($result->isConsistent())->toBeTrue();
});

it('LEDGER-05: abandoning a payout reverses the debit exactly once and the ledger stays consistent', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '100.00');

    $payout = app(PayoutService::class)->request($store, 'EUR', '80.00', 'ledger-payout-key-3');
    app(PayoutService::class)->abandon($payout, PayoutStatus::Cancelled, 'ledger test cancellation');

    $result = app(WalletLedgerAuditor::class)->auditWallet($wallet->fresh());

    expect($wallet->fresh()->balance)->toBe('100.00')
        ->and($result->expectedBalance)->toBe('100.00')
        ->and($result->completedTransactionCount)->toBe(3) // sale + withdrawal + withdrawal_reversal — all three stand; net effect is zero
        ->and($result->isConsistent())->toBeTrue();
});
