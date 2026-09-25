<?php

/**
 * The other half of the Wallet single-writer guarantee (CROSS-12, PAYOUT-16).
 * tests/Architecture/WalletLedgerSingleWriterTest proves that only
 * App\Services\Wallet\WalletTransactionService writes
 * `store_wallet_transactions` or `store_wallets.balance`. It does not prove
 * who may *call* that writer: a new controller, job, listener, command or
 * provider adapter could inject WalletTransactionService and call `record()`
 * without containing a single write primitive that test scans for, and the
 * two `*RecoveryNoDirectWalletMutationTest` files only guard a fixed list of
 * admin/recovery files.
 *
 * This test closes that gap with an EXACT set: the production files that
 * reference WalletTransactionService are precisely the writer itself plus
 * the canonical financial services docs/financial/MONEY-FLOWS.md names as
 * the origin of every Wallet effect:
 *
 * - PaymentService — the pending `sale` at claim time (MONEY-FLOWS §A/§J);
 * - PaymentEventProcessor — confirm / mark failed / full-refund reversal
 *   (MONEY-FLOWS §A–§C);
 * - PayoutService — the `withdrawal` at request time and the single
 *   `withdrawal_reversal` in abandon() (MONEY-FLOWS §E/§H).
 *
 * Exact set, not "no offenders": a new caller fails this test, and so does a
 * canonical service that stops calling the writer without the allowlist
 * shrinking with it. Adding a caller is an architectural decision
 * (docs/financial/ARCHITECTURE.md §8) that must change this list on purpose,
 * in the same PR as the documentation.
 *
 * ## What is scanned
 *
 * "Production code" = PHP under app/, routes/, bootstrap/ (except the
 * generated bootstrap/cache), config/ and database/seeders/, comments
 * stripped — the same roots and technique as InventoryBoundaryTest /
 * OrderLifecycleBoundaryTest. Tests, factories and migrations are outside
 * the boundary.
 *
 * ## What it does NOT prove
 *
 * A class resolved under a different name (a container alias, a
 * runtime-assembled class string) evades a text scan, and code run outside
 * the repository (tinker, a database console) is beyond any test. It says
 * nothing about whether an allowlisted service calls the writer correctly —
 * that is the job of the financial Feature suites.
 */
function walletCallerStrip(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/** @return array<string, string> repo-relative path => comment-stripped code */
function walletCallerLoadProduction(): array
{
    $files = [];

    foreach (['app', 'routes', 'bootstrap', 'config', 'database/seeders'] as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

            if (str_starts_with($relative, 'bootstrap/cache/')) {
                continue;
            }

            $files[$relative] = walletCallerStrip(file_get_contents($file->getPathname()));
        }
    }

    return $files;
}

/** @return list<string> exact repo-relative paths, sorted */
function walletCallerAllowList(): array
{
    return [
        'app/Domain/Payments/Services/PaymentEventProcessor.php',
        'app/Domain/Payments/Services/PaymentService.php',
        'app/Domain/Payouts/Services/PayoutService.php',
        'app/Services/Wallet/WalletTransactionService.php',
    ];
}

/**
 * Every file whose code references the Wallet writer — by import, `::class`,
 * type declaration, container resolution or class-name string.
 *
 * @param  array<string, string>  $files
 * @return list<string> sorted
 */
function walletCallerReferencingFiles(array $files): array
{
    $referencing = array_keys(array_filter(
        $files,
        fn (string $code) => preg_match('/\bWalletTransactionService\b/', $code) === 1,
    ));

    sort($referencing);

    return $referencing;
}

// ---------------------------------------------------------------------
// Real scan of the real tree
// ---------------------------------------------------------------------

it('scans a non-vacuous production tree', function () {
    expect(count(walletCallerLoadProduction()))->toBeGreaterThan(100);
});

it('lets exactly the canonical financial services, and nothing else in production code, reference the Wallet writer (CROSS-12, PAYOUT-16)', function () {
    expect(walletCallerReferencingFiles(walletCallerLoadProduction()))->toBe(walletCallerAllowList());
});

// ---------------------------------------------------------------------
// Self-tests
// ---------------------------------------------------------------------

it('[self-test] the detector flags every way of reaching the Wallet writer, and ignores comments and unrelated names', function () {
    $files = [
        'app/Http/Controllers/Imported.php' => walletCallerStrip('<?php use App\Services\Wallet\WalletTransactionService; class A {}'),
        'app/Jobs/Injected.php' => walletCallerStrip('<?php class B { public function __construct(private \App\Services\Wallet\WalletTransactionService $w) {} }'),
        'app/Listeners/Resolved.php' => walletCallerStrip('<?php app(\App\Services\Wallet\WalletTransactionService::class)->record();'),
        'routes/by-string.php' => walletCallerStrip('<?php app("App\\\\Services\\\\Wallet\\\\WalletTransactionService");'),
        'app/Commented.php' => walletCallerStrip("<?php\n// WalletTransactionService is the only writer\n/** @see WalletTransactionService */ class C {}"),
        'app/Unrelated.php' => walletCallerStrip('<?php class WalletTransactionServiceFactory {} class MyWalletTransactionService {}'),
    ];

    expect(walletCallerReferencingFiles($files))->toBe([
        'app/Http/Controllers/Imported.php',
        'app/Jobs/Injected.php',
        'app/Listeners/Resolved.php',
        'routes/by-string.php',
    ]);
});

it('[self-test] the allowlist is exact paths: a same-named file elsewhere is not allow-listed', function () {
    $allowed = walletCallerAllowList();

    foreach ([
        'app/Http/Controllers/PaymentService.php',
        'app/Domain/Orders/Services/PayoutService.php',
        'app/Anything/PaymentEventProcessor.php',
        'app/WalletTransactionService.php',
    ] as $impostor) {
        expect(in_array($impostor, $allowed, true))->toBeFalse();
    }

    expect($allowed)->toHaveCount(4);
});
