<?php

use App\Domain\Wallet\Exceptions\InsufficientWalletBalanceException;
use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Services\Wallet\WalletLedgerAuditor;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WalletTransactionService;

function auditorService(): WalletLedgerAuditor
{
    return app(WalletLedgerAuditor::class);
}

it('LEDGER-03: expected balance equals completed credits minus completed debits over the wallet\'s entire history', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    // Only the categories with a real producer in this codebase today
    // (sale, customer_refund, withdrawal, withdrawal_reversal) — see this
    // branch's own design record for why the other 11 seeded categories
    // are deliberately left out of these tests rather than exercised via
    // a fabricated producer.
    $service->record($wallet, 'sale', '100.00');
    $service->record($wallet, 'customer_refund', '10.00');
    $service->record($wallet, 'sale', '25.00');
    $service->record($wallet, 'withdrawal', '5.00');

    $result = auditorService()->auditWallet($wallet->fresh());

    expect($result->expectedBalance)->toBe('110.00')
        ->and($wallet->fresh()->balance)->toBe('110.00')
        ->and($result->isConsistent())->toBeTrue()
        ->and($result->completedTransactionCount)->toBe(4);
});

it('LEDGER-03/LEDGER-02: pending and failed transactions never contribute to expected balance', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    $service->record($wallet, 'sale', '100.00');
    $pending = $service->record($wallet, 'sale', '50.00', options: ['status' => 'pending']);
    $service->markFailed($pending->fresh());

    $anotherPending = $service->record($wallet, 'withdrawal', '20.00', options: ['status' => 'pending']);

    $result = auditorService()->auditWallet($wallet->fresh());

    expect($result->expectedBalance)->toBe('100.00')
        ->and($wallet->fresh()->balance)->toBe('100.00')
        ->and($result->completedTransactionCount)->toBe(1)
        ->and($result->isConsistent())->toBeTrue();

    // The still-pending one never got resolved either way — confirms it
    // really is excluded, not coincidentally zero.
    expect($anotherPending->fresh()->isPending())->toBeTrue();
});

it('LEDGER-06: currency isolation — a store\'s two wallets never leak into each other\'s expected balance', function () {
    $store = Store::factory()->create();
    $walletService = app(WalletService::class);
    $eur = $walletService->getWallet($store, 'EUR');
    $usd = $walletService->getOrCreateWallet($store, 'USD');
    $service = app(WalletTransactionService::class);

    $service->record($eur, 'sale', '100.00');
    $service->record($usd, 'sale', '200.00');
    $service->record($usd, 'withdrawal', '15.00');

    $eurResult = auditorService()->auditWallet($eur->fresh());
    $usdResult = auditorService()->auditWallet($usd->fresh());

    expect($eurResult->expectedBalance)->toBe('100.00')
        ->and($eurResult->currencyCode)->toBe('EUR')
        ->and($usdResult->expectedBalance)->toBe('185.00')
        ->and($usdResult->currencyCode)->toBe('USD')
        ->and($eurResult->isConsistent())->toBeTrue()
        ->and($usdResult->isConsistent())->toBeTrue();
});

it('LEDGER-07 DRIFT SCENARIO: wallet balance 100.00, ledger expects 80.00 — detected, reported, zero writes', function () {
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    app(WalletTransactionService::class)->record($wallet, 'sale', '80.00');

    // Simulate drift: something (a bug, a manual DB edit) moved the stored
    // balance to 100.00 without a matching ledger entry — the one thing
    // WalletLedgerAuditor must detect, never silently accept or correct.
    $wallet->update(['balance' => '100.00']);

    $beforeTransactionCount = StoreWalletTransaction::count();
    $beforeSnapshot = StoreWalletTransaction::query()->orderBy('id')->get(['id', 'transaction_status_id', 'amount', 'balance_after', 'updated_at'])->toArray();
    $beforeWalletUpdatedAt = $wallet->fresh()->updated_at;

    $result = auditorService()->auditWallet($wallet->fresh());

    expect($result->expectedBalance)->toBe('80.00')
        ->and($result->actualBalance)->toBe('100.00')
        ->and($result->difference)->toBe('-20.00')
        ->and($result->isConsistent())->toBeFalse();

    // Zero mutation: the wallet, every transaction, and their timestamps
    // are byte-for-byte identical to before the audit ran.
    expect($wallet->fresh()->balance)->toBe('100.00')
        ->and($wallet->fresh()->updated_at->eq($beforeWalletUpdatedAt))->toBeTrue()
        ->and(StoreWalletTransaction::count())->toBe($beforeTransactionCount)
        ->and(StoreWalletTransaction::query()->orderBy('id')->get(['id', 'transaction_status_id', 'amount', 'balance_after', 'updated_at'])->toArray())->toBe($beforeSnapshot);
});

it('LEDGER-07: auditAll reports every drifted wallet and none of the consistent ones', function () {
    $healthyStore = Store::factory()->create();
    $healthyWallet = app(WalletService::class)->getWallet($healthyStore, 'EUR');
    app(WalletTransactionService::class)->record($healthyWallet, 'sale', '50.00');

    $driftedStore = Store::factory()->create();
    $driftedWallet = app(WalletService::class)->getWallet($driftedStore, 'EUR');
    app(WalletTransactionService::class)->record($driftedWallet, 'sale', '80.00');
    $driftedWallet->update(['balance' => '100.00']);

    $report = auditorService()->auditAll();

    expect($report->isHealthy())->toBeFalse()
        ->and($report->totalAudited)->toBe(2)
        ->and(count($report->mismatches))->toBe(1)
        ->and($report->mismatches[0]->walletId)->toBe($driftedWallet->id);
});

it('auditAll scoped by store never audits another store\'s wallet', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    app(WalletTransactionService::class)->record(app(WalletService::class)->getWallet($storeA, 'EUR'), 'sale', '10.00');
    $walletB = app(WalletService::class)->getWallet($storeB, 'EUR');
    app(WalletTransactionService::class)->record($walletB, 'sale', '80.00');
    $walletB->update(['balance' => '100.00']);

    $report = auditorService()->auditAll(storeId: $storeA->id);

    expect($report->totalAudited)->toBe(1)
        ->and($report->isHealthy())->toBeTrue();
});

it('CONCURRENCY (sequential simulation — see note): the ledger stays reconcilable when two debits race against the same balance', function () {
    // Single-process/SQLite test harness cannot run two real concurrent DB
    // transactions — same documented limitation as
    // PayoutServiceRequestTest::'it never lets two payouts reserve more...'
    // and PaymentAttemptIdempotencyKeyUniquenessTest elsewhere in this
    // codebase. What this proves: the SAME lock (WalletTransactionService::lockWallet(),
    // a real SELECT ... FOR UPDATE) that already serializes concurrent
    // debits is what WalletLedgerAuditor's correctness depends on — a
    // wallet's completed ledger is only ever the product of that lock's
    // serialization, one debit fully applied before the next is even
    // evaluated, never a torn/partial write for the auditor to
    // misinterpret. Locking/CAS primitives exercised here; true parallel
    // PostgreSQL races are not executed by this test harness.
    $store = Store::factory()->create();
    $wallet = app(WalletService::class)->getWallet($store, 'EUR');
    $service = app(WalletTransactionService::class);

    $service->record($wallet, 'sale', '100.00');

    $service->record($wallet, 'withdrawal', '80.00');

    expect(fn () => $service->record($wallet, 'withdrawal', '80.00'))
        ->toThrow(InsufficientWalletBalanceException::class);

    $result = auditorService()->auditWallet($wallet->fresh());

    expect($wallet->fresh()->balance)->toBe('20.00')
        ->and($result->expectedBalance)->toBe('20.00')
        ->and($result->isConsistent())->toBeTrue();
});
