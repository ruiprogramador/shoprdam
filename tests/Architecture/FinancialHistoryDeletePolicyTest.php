<?php

/**
 * Text-based, migration-source complement to
 * tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest, which reads
 * the *actual* constraint back from a migrated database via `PRAGMA
 * foreign_key_list` — the real proof. This file cannot and does not prove
 * runtime DB behavior on its own; it only prevents a future migration or
 * model from silently reintroducing one of the three regression shapes
 * already guarded on the Payouts side by
 * tests/Architecture/PayoutFinancialHistoryAppendOnlyTest: a cascade-delete
 * policy, a SoftDeletes substitute, or application code truncating/dropping
 * the table directly.
 *
 * Unlike Payouts (restrictOnDelete() from its very first migration), each
 * of the six FKs CROSS-14 protects legitimately started out as
 * cascadeOnDelete() in its original create_*_table migration — that
 * historical file is never edited — and was only fixed by the later
 * database/migrations/2026_09_16_180000_restrict_financial_history_cascades.php.
 * So "no migration file may ever contain cascadeOnDelete() for this
 * column" (PayoutFinancialHistoryAppendOnlyTest's own, simpler check) would
 * be permanently false here. The two tests below are scoped instead to
 * exactly what can still regress going forward: the hardening migration
 * itself, and any migration added after it.
 */
const CROSS_14_HARDENING_MIGRATION = 'migrations/2026_09_16_180000_restrict_financial_history_cascades.php';

/** @return array<int, array{table: string, column: string}> */
function cross14ProtectedForeignKeys(): array
{
    return [
        ['table' => 'payment_attempts', 'column' => 'payment_id'],
        ['table' => 'payments', 'column' => 'order_id'],
        ['table' => 'store_wallet_transactions', 'column' => 'store_wallet_id'],
        ['table' => 'orders', 'column' => 'store_id'],
        ['table' => 'store_wallets', 'column' => 'store_id'],
        ['table' => 'stores', 'column' => 'user_id'],
    ];
}

it('keeps the CROSS-14 hardening migration declaring restrictOnDelete() for exactly its six protected foreign keys, with no cascadeOnDelete() in up()', function () {
    $path = database_path(CROSS_14_HARDENING_MIGRATION);

    expect(file_exists($path))->toBeTrue("Expected {$path} to exist — this is the CROSS-14 hardening migration itself.");

    $contents = file_get_contents($path);

    $upStart = strpos($contents, 'function up(): void');
    $downStart = strpos($contents, 'function down(): void');

    expect($upStart)->not->toBeFalse()
        ->and($downStart)->not->toBeFalse()
        ->and($downStart)->toBeGreaterThan($upStart);

    $up = substr($contents, $upStart, $downStart - $upStart);

    expect($up)->not->toContain('cascadeOnDelete');

    preg_match_all("/'table' => '([a-z_]+)', 'column' => '([a-z_]+)'/", $contents, $matches, PREG_SET_ORDER);

    $declaredPairs = collect($matches)->map(fn ($m) => "{$m[1]}.{$m[2]}")->sort()->values()->all();
    $expectedPairs = collect(cross14ProtectedForeignKeys())->map(fn ($fk) => "{$fk['table']}.{$fk['column']}")->sort()->values()->all();

    expect($declaredPairs)->toBe($expectedPairs);

    // The six pairs above are applied via a data-driven foreach (see the
    // migration's own $foreignKeys property), not six separate literal
    // statements, so the source only ever contains one textual
    // restrictOnDelete() call to find — asserting the pair list matches
    // exactly (above) is what actually proves all six are covered.
    expect($up)->toContain('restrictOnDelete()');
});

/**
 * Heuristic, not a full table+column parser: matches on column NAME alone
 * (e.g. any `foreignId('store_id')->...->cascadeOnDelete()`), across every
 * migration filed *after* the hardening migration. This can't distinguish
 * "store_id on the protected orders/store_wallets tables" from "store_id on
 * some unrelated future table" — an acceptable, documented trade-off for a
 * fast, fail-closed guardrail; a genuinely new table needing
 * cascadeOnDelete() on a same-named column is rare, and the real,
 * table-and-column-precise proof is
 * tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest against the
 * live schema. Historical migrations before the hardening fix are
 * deliberately excluded — see this file's own docblock for why they
 * legitimately still say cascadeOnDelete().
 */
it('never lets a migration added after the CROSS-14 hardening fix reintroduce cascadeOnDelete() on a protected financial foreign key', function () {
    $protectedColumns = collect(cross14ProtectedForeignKeys())->pluck('column')->unique()->values()->all();

    $futureFiles = collect(glob(database_path('migrations/*.php')))
        ->map(fn ($path) => basename($path))
        ->filter(fn ($name) => $name > basename(CROSS_14_HARDENING_MIGRATION))
        ->values();

    $offenders = [];

    foreach ($futureFiles as $name) {
        $contents = file_get_contents(database_path("migrations/{$name}"));

        foreach ($protectedColumns as $column) {
            $quoted = preg_quote($column, '/');

            if (preg_match("/foreignId\('{$quoted}'\)[^;]*cascadeOnDelete/s", $contents)
                || preg_match("/foreign\(\[?'?{$quoted}'?\]?\)[^;]*cascadeOnDelete/s", $contents)
            ) {
                $offenders[] = "{$name}: {$column}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * Store is deliberately excluded: it already has SoftDeletes for its own,
 * unrelated reasons predating this branch (a store can be deactivated) —
 * and a DB-level cascade bypasses Eloquent's SoftDeletes entirely anyway
 * (see docs/financial/FAILURE-MODEL.md's "Critical finding" reproduction,
 * step 3), so SoftDeletes on Store was never what stops CROSS-14; the
 * restrictOnDelete() constraint is. What this guards against is one of the
 * *other* five models gaining SoftDeletes later as a way to look like this
 * was "fixed" without the real DB constraint.
 */
it('never lets a Payments/Wallet financial model use soft deletes as a substitute for the database-level restrictOnDelete() guarantee', function () {
    $files = [
        app_path('Models/StoreWallet.php'),
        app_path('Models/StoreWalletTransaction.php'),
        app_path('Models/Order.php'),
        app_path('Domain/Payments/Models/Payment.php'),
        app_path('Domain/Payments/Models/PaymentAttempt.php'),
    ];

    $offenders = [];

    foreach ($files as $file) {
        expect(file_exists($file))->toBeTrue("Expected {$file} to exist.");

        if (str_contains(file_get_contents($file), 'SoftDeletes')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('never lets application code truncate or drop a Payments/Wallet financial-history table directly', function () {
    $tables = ['stores', 'store_wallets', 'store_wallet_transactions', 'orders', 'payments', 'payment_attempts'];

    $offenders = [];

    $files = iterator_to_array(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    ));

    foreach ($files as $file) {
        if (! is_file($file->getPathname()) || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        if (str_contains($contents, '::truncate(') || str_contains($contents, '->truncate(')) {
            $offenders[] = "{$relativePath} calls truncate()";
        }

        foreach ($tables as $table) {
            if (preg_match('/DB::statement\([^)]*\b(TRUNCATE|DROP\s+TABLE)\b[^)]*'.preg_quote($table, '/').'/i', $contents)) {
                $offenders[] = "{$relativePath} contains a raw TRUNCATE/DROP statement referencing {$table}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
