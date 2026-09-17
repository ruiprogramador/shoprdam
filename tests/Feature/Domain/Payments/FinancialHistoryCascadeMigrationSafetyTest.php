<?php

use Database\Seeders\TestingLookupSeeder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proves database/migrations/2026_09_16_180000_restrict_financial_history_cascades.php
 * is genuinely safe to run against a database that already holds real
 * financial history — the exact claim that migration's own docblock makes
 * and points here for.
 *
 * Isolation strategy (why this doesn't run inside the ordinary Feature-test
 * database): every other Feature test shares one process-wide `:memory:`
 * SQLite connection, migrated exactly once
 * (Illuminate\Foundation\Testing\RefreshDatabase caches that in
 * RefreshDatabaseState::$migrated) and wrapped per-test in a transaction
 * that's rolled back afterward — see that trait's own source. Re-running a
 * migration's up()/down() there would mean rebuilding six live tables
 * (SQLite has no native ALTER TABLE DROP CONSTRAINT; Laravel's SQLite
 * grammar rebuilds the whole table to add/drop a foreign key) *nested*
 * inside that already-open per-test transaction, on the one connection
 * every other test in the same process still depends on — a correctness
 * and isolation risk with no benefit over a dedicated connection.
 *
 * Instead, each test here spins up its own private, disposable `:memory:`
 * SQLite connection (registered only in `config()` for the life of one
 * test, purged in afterEach()), runs the real `migrate` artisan command
 * against it, and talks to it exclusively via DB::connection() / raw
 * DB::table() inserts — Eloquent models stay bound to the default
 * connection and are never used here. Calling the migration's own up()/
 * down() methods requires them to run against this connection too: they're
 * written against the `Schema` facade with no explicit connection, which
 * (see Illuminate\Support\Facades\Schema — `protected static $cached =
 * false;` — and Illuminate\Database\DatabaseServiceProvider's `db.schema`
 * binding) always re-resolves `app('db')->connection()` fresh from
 * `config('database.default')` on every call rather than caching a
 * connection at boot. So swapping `database.default` immediately before
 * calling up()/down(), and restoring it immediately after in a finally
 * block, correctly and narrowly targets this isolated connection without
 * ever touching the real default connection.
 *
 * Nothing here can reach database/database.sqlite (never referenced) or
 * the shared Feature-test `:memory:` database (a distinct, separately
 * named connection).
 */
const MIGRATION_SAFETY_CONNECTION = 'migration_safety_test';

const MIGRATION_UNDER_TEST = 'migrations/2026_09_16_180000_restrict_financial_history_cascades.php';

function migrationSafetyDb(): Connection
{
    return DB::connection(MIGRATION_SAFETY_CONNECTION);
}

/** @return array<int, array{table: string, column: string}> */
function protectedForeignKeys(): array
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

function fkDeletePolicies(string $table): array
{
    return collect(migrationSafetyDb()->select("PRAGMA foreign_key_list('{$table}')"))
        ->mapWithKeys(fn ($row) => [$row->from => $row->on_delete])
        ->all();
}

function assertProtectedFksAre(string $expectedPolicy): void
{
    foreach (protectedForeignKeys() as $fk) {
        expect(fkDeletePolicies($fk['table'])[$fk['column']])
            ->toBe($expectedPolicy, "Expected {$fk['table']}.{$fk['column']} to be {$expectedPolicy}.");
    }
}

/**
 * Runs the real migration's up()/down() against the isolated connection —
 * not a reimplementation of its logic — by requiring the actual file fresh
 * (a plain require, not require_once, deliberately: PHP allows an
 * anonymous class to be declared again from a second require of the same
 * file, producing an independent, fully usable instance each time — this
 * doesn't reuse or interfere with whatever Illuminate's own Migrator
 * cached internally while running `migrate` against this same connection
 * moments earlier).
 */
function runMigrationMethod(string $method): void
{
    $migration = require database_path(MIGRATION_UNDER_TEST);

    $previousDefault = config('database.default');
    config(['database.default' => MIGRATION_SAFETY_CONNECTION]);

    try {
        $migration->{$method}();
    } finally {
        config(['database.default' => $previousDefault]);
    }
}

/**
 * Seeds one real row of financial history through every table in the
 * CROSS-14 graph via raw DB::table() inserts against the isolated
 * connection. Returns the ids needed to fetch and compare a snapshot
 * later.
 */
function seedFinancialHistory(): array
{
    $db = migrationSafetyDb();

    $userTypeId = $db->table('user_types')->value('id');

    $userId = $db->table('users')->insertGetId([
        'user_type_id' => $userTypeId,
        'name' => 'Migration Safety Vendor',
        'email' => 'migration-safety-'.Str::random(8).'@example.test',
        'password' => 'irrelevant-for-this-test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $storeStatusId = $db->table('store_statuses')->value('id');

    $storeId = $db->table('stores')->insertGetId([
        'user_id' => $userId,
        'store_status_id' => $storeStatusId,
        'slug' => 'migration-safety-store-'.Str::random(8),
        'name' => 'Migration Safety Store',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $currencyId = $db->table('currencies')->where('code', 'EUR')->value('id');

    $storeWalletId = $db->table('store_wallets')->insertGetId([
        'store_id' => $storeId,
        'currency_id' => $currencyId,
        'balance' => '250.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderStatusId = $db->table('order_statuses')->where('slug', 'pending')->value('id');

    $orderId = $db->table('orders')->insertGetId([
        'store_id' => $storeId,
        'currency_id' => $currencyId,
        'order_status_id' => $orderStatusId,
        'amount' => '75.00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $paymentId = $db->table('payments')->insertGetId([
        'order_id' => $orderId,
        'status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $paymentAttemptId = $db->table('payment_attempts')->insertGetId([
        'payment_id' => $paymentId,
        'provider' => 'fake_migration_safety',
        'method' => 'card',
        'provider_reference' => 'migration-safety-ref-'.Str::random(8),
        'idempotency_key' => 'migration-safety-key-'.Str::random(8),
        'status' => 'succeeded',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $transactionCategoryId = $db->table('transaction_categories')->where('slug', 'sale')->value('id');
    $transactionStatusId = $db->table('transaction_statuses')->where('slug', 'completed')->value('id');

    $storeWalletTransactionId = $db->table('store_wallet_transactions')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'store_wallet_id' => $storeWalletId,
        'transaction_category_id' => $transactionCategoryId,
        'transaction_status_id' => $transactionStatusId,
        'amount' => '75.00',
        'balance_after' => '250.00',
        'external_provider' => 'migration_safety_test',
        'external_reference' => 'migration-safety-tx-'.Str::random(8),
        'source' => 'system',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact(
        'userId', 'storeId', 'storeWalletId', 'orderId',
        'paymentId', 'paymentAttemptId', 'storeWalletTransactionId'
    );
}

function snapshotFinancialHistory(array $ids): array
{
    $db = migrationSafetyDb();

    return [
        'user' => (array) $db->table('users')->find($ids['userId']),
        'store' => (array) $db->table('stores')->find($ids['storeId']),
        'store_wallet' => (array) $db->table('store_wallets')->find($ids['storeWalletId']),
        'order' => (array) $db->table('orders')->find($ids['orderId']),
        'payment' => (array) $db->table('payments')->find($ids['paymentId']),
        'payment_attempt' => (array) $db->table('payment_attempts')->find($ids['paymentAttemptId']),
        'store_wallet_transaction' => (array) $db->table('store_wallet_transactions')->find($ids['storeWalletTransactionId']),
    ];
}

beforeEach(function () {
    config(['database.connections.'.MIGRATION_SAFETY_CONNECTION => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    // Nnjeim\World\Database\Migrations\BaseMigration (countries/currencies/
    // states/cities) hardcodes its OWN connection from config('world.connection')
    // at construction time — it ignores `migrate --database=` entirely (see
    // this file's own investigation, kept here since it's exactly the kind
    // of surprising, non-obvious constraint a future reader would otherwise
    // have to rediscover). Pointing that config at our isolated connection
    // too, only for the duration of this test, is what actually makes
    // `--database` apply uniformly to every migration this app has,
    // including the vendor-published ones. Restored to the literal 'sqlite'
    // afterward rather than captured dynamically: config('world.connection')
    // is env('WORLD_DB_CONNECTION', env('DB_CONNECTION')), and DB_CONNECTION
    // is fixed to 'sqlite' for this entire test run by phpunit.xml.
    config(['world.connection' => MIGRATION_SAFETY_CONNECTION]);

    Artisan::call('migrate', [
        '--database' => MIGRATION_SAFETY_CONNECTION,
        '--force' => true,
    ]);

    Artisan::call('db:seed', [
        '--class' => TestingLookupSeeder::class,
        '--database' => MIGRATION_SAFETY_CONNECTION,
        '--force' => true,
    ]);
});

afterEach(function () {
    config(['world.connection' => 'sqlite']);
    DB::purge(MIGRATION_SAFETY_CONNECTION);
});

it('starts, after a fresh migrate, with every CROSS-14 foreign key already RESTRICT — the isolation harness itself is sound', function () {
    assertProtectedFksAre('RESTRICT');
});

it('lets existing financial rows survive down()+up() unmodified, and correctly flips the live FK policy both ways', function () {
    $ids = seedFinancialHistory();
    $before = snapshotFinancialHistory($ids);

    // down(): every protected FK must become CASCADE again — reopening the
    // exact CROSS-14 gap this migration closes — and every existing row
    // must still be present and byte-for-byte unmodified; down() is
    // schema-only, it must never touch data.
    runMigrationMethod('down');

    assertProtectedFksAre('CASCADE');
    expect(snapshotFinancialHistory($ids))->toBe($before);

    // up(): every protected FK must be RESTRICT again, rows still intact.
    runMigrationMethod('up');

    assertProtectedFksAre('RESTRICT');
    expect(snapshotFinancialHistory($ids))->toBe($before);
});
