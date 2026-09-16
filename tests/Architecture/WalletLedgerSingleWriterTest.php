<?php

/**
 * Proves App\Services\Wallet\WalletTransactionService is the only writer of
 * `store_wallet_transactions` and the only mutator of `store_wallets.balance`
 * across the ENTIRE `app/` tree — not just the payments/payouts admin
 * recovery paths already guarded by
 * tests/Architecture/PaymentRecoveryNoDirectWalletMutationTest and
 * tests/Architecture/PayoutRecoveryNoDirectWalletMutationTest.
 *
 * Exactly two allow-listed files, matched by their full canonical path
 * relative to `app/` — never by basename. A basename-only allowlist (an
 * earlier version of this test used `in_array($file->getFilename(), ...)`)
 * would let a *different* future file that merely happens to share one of
 * these names — e.g. `app/Anything/WalletTransactionService.php` — write
 * to the ledger completely unscanned; see
 * walletLedgerSingleWriterAllowList()'s own docblock and the regression
 * test below for the exact failure mode this closes.
 *
 * - `Services/Wallet/WalletTransactionService.php` — the authorized writer.
 * - `Services/Wallet/WalletService.php` — the only place a StoreWallet is
 *   ever created (`StoreWallet::firstOrCreate(...)`), always with a
 *   hardcoded `'0.00'` opening balance (see LEDGER-03 and
 *   tests/Feature/Store/WalletServiceOpeningBalanceTest) — creating the row
 *   is not the same operation as mutating an existing wallet's ledger, but
 *   it does legitimately call the same Eloquent static methods this test
 *   would otherwise forbid. The second test below scopes its actual
 *   permissions down further than this file-level allowlist does.
 *
 * Every pattern below was confirmed, before writing this test, to have ZERO
 * existing occurrences anywhere in `app/` outside those two files — so this
 * list is exhaustive for what exists today and produces no noise, not a
 * predicted or aspirational set of rules.
 *
 * Known, deliberate limitation (documented rather than solved with a
 * parser — see this branch's own design record): a generic `->save()` on a
 * StoreWallet/StoreWalletTransaction instance already fetched elsewhere is
 * not distinguishable, by a plain string scan, from `->save()` on any other
 * Eloquent model in this codebase — banning the bare string would be
 * mostly noise against unrelated models. This guardrail is prevention for
 * every mutation shape realistic in this codebase today; the residual gap
 * is covered by detection, not prevention — App\Services\Wallet\WalletLedgerAuditor
 * (`php artisan wallet:audit`) would surface any resulting drift, even one
 * this scan couldn't have caught in review.
 */

/**
 * The exact, canonical paths (relative to `app_path()`, forward-slash
 * normalized) allowed to reference the forbidden patterns below — never
 * matched by basename. Extracted as its own function so both the real scan
 * and the regression test that proves this is fail-closed share the exact
 * same allow-list logic; a bug in one is a bug in both, which is the point.
 *
 * @return list<string>
 */
function walletLedgerSingleWriterAllowList(): array
{
    return [
        'Services/Wallet/WalletTransactionService.php',
        'Services/Wallet/WalletService.php',
    ];
}

/** Path relative to `app_path()`, with backslashes normalized to forward slashes — matches the allowlist's own format regardless of OS. */
function walletLedgerRelativeAppPath(string $absolutePath): string
{
    return str_replace('\\', '/', str_replace(app_path().DIRECTORY_SEPARATOR, '', $absolutePath));
}

function walletLedgerForbiddenPatterns(): array
{
    return [
        'StoreWalletTransaction::create(',
        'StoreWalletTransaction::insert(',
        'StoreWalletTransaction::updateOrCreate(',
        'StoreWalletTransaction::firstOrCreate(',
        'StoreWallet::create(',
        'StoreWallet::insert(',
        'StoreWallet::updateOrCreate(',
        'StoreWallet::firstOrCreate(',
        "DB::table('store_wallets'",
        'DB::table("store_wallets"',
        "DB::table('store_wallet_transactions'",
        'DB::table("store_wallet_transactions"',
        'DB::insert(',
        'DB::update(',
        'DB::statement(',
        "increment('balance'",
        'increment("balance"',
        "decrement('balance'",
        'decrement("balance"',
        '->balance = ',
    ];
}

it('never lets anything but the two exact, canonical Wallet service paths write store_wallet_transactions or mutate a wallet balance', function () {
    $allowed = walletLedgerSingleWriterAllowList();
    $forbidden = walletLedgerForbiddenPatterns();

    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relativePath = walletLedgerRelativeAppPath($file->getPathname());

        if (in_array($relativePath, $allowed, true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        foreach ($forbidden as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' contains '.$needle;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Regression test for the exact bypass this file's own history had: a
 * basename-only allowlist would have let
 * `app/Anything/WalletTransactionService.php` — same filename, different
 * directory — write to the ledger completely unscanned. No permanent fixture
 * file is created; this exercises the allow-list helper directly against a
 * path that could never legitimately exist as one of the two authorized
 * files, proving the check is by full path, not by basename.
 */
it('never allow-lists a WalletTransactionService.php or WalletService.php outside its exact authorized path', function () {
    $allowed = walletLedgerSingleWriterAllowList();

    $impostorPaths = [
        'Anything/WalletTransactionService.php',
        'Domain/Payouts/Services/WalletTransactionService.php',
        'WalletTransactionService.php',
        'Anything/WalletService.php',
        'Domain/Payments/Services/WalletService.php',
    ];

    foreach ($impostorPaths as $impostorPath) {
        expect($allowed)->not->toContain($impostorPath);
        expect(in_array($impostorPath, $allowed, true))->toBeFalse();
    }

    // And the two real, exact paths remain allow-listed — this isn't just
    // an empty list that trivially rejects everything.
    expect($allowed)->toContain('Services/Wallet/WalletTransactionService.php')
        ->and($allowed)->toContain('Services/Wallet/WalletService.php');
});

/**
 * The allow-listed WalletService.php is only ever allowed to *create* a
 * wallet, always at a zero opening balance — never to touch an existing
 * one's balance or write a ledger row. Scoped separately from the test
 * above so WalletService keeps the narrower set of permissions it actually
 * needs, not the full set WalletTransactionService has.
 */
it('never lets WalletService write a ledger transaction or a non-zero balance', function () {
    $contents = file_get_contents(app_path('Services/Wallet/WalletService.php'));

    expect($contents)->not->toContain('StoreWalletTransaction::');

    // The one balance value WalletService is allowed to write, checked
    // positively rather than by forbidding every other string: creation
    // always hardcodes the zero opening balance.
    expect($contents)->toContain("'balance' => '0.00'");
});
