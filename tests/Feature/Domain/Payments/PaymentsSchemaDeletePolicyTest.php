<?php

/**
 * Complements tests/Architecture/FinancialHistoryDeletePolicyTest's
 * text-based scan of the migration source with a check of the *actual*
 * constraint SQLite enforces, read straight from the migrated schema via
 * `PRAGMA foreign_key_list` — exactly the same pattern already proven by
 * tests/Feature/Domain/Payouts/PayoutSchemaDeletePolicyTest, now applied to
 * the Payments/Wallet side that CROSS-14 previously left unprotected (see
 * docs/financial/INVARIANTS.md and the migration this test verifies:
 * database/migrations/2026_09_16_180000_restrict_financial_history_cascades.php).
 *
 * This is a Feature test, not an Architecture one, precisely because it
 * needs a real migrated database — see tests/Pest.php for why Architecture
 * tests deliberately don't get one.
 */
function financialForeignKeyDeleteRules(string $table): array
{
    return dbForeignKeyDeleteRules($table);
}

it('enforces RESTRICT at the database level for stores.user_id', function () {
    expect(financialForeignKeyDeleteRules('stores'))->toMatchArray([
        'user_id' => 'RESTRICT',
        // Non-financial identity — deliberately not restrict; see the
        // migration's own comment.
        'verified_by' => 'SET NULL',
    ]);
});

it('enforces RESTRICT at the database level for store_wallets.store_id', function () {
    expect(financialForeignKeyDeleteRules('store_wallets'))->toMatchArray([
        'store_id' => 'RESTRICT',
        'currency_id' => 'RESTRICT',
    ]);
});

it('enforces RESTRICT at the database level for store_wallet_transactions.store_wallet_id', function () {
    $rules = financialForeignKeyDeleteRules('store_wallet_transactions');

    expect($rules)->toMatchArray([
        'store_wallet_id' => 'RESTRICT',
        'transaction_category_id' => 'RESTRICT',
        'transaction_status_id' => 'RESTRICT',
        // A pointer between ledger rows, not the row itself — nulling it on
        // delete is correct, not a gap (see the migration's own comment).
        'related_transaction_id' => 'SET NULL',
        'created_by' => 'SET NULL',
    ]);
});

it('enforces RESTRICT at the database level for orders.store_id', function () {
    expect(financialForeignKeyDeleteRules('orders'))->toMatchArray([
        'store_id' => 'RESTRICT',
        'currency_id' => 'RESTRICT',
        'order_status_id' => 'RESTRICT',
    ]);
});

it('enforces RESTRICT at the database level for payments.order_id', function () {
    expect(financialForeignKeyDeleteRules('payments'))->toMatchArray([
        'order_id' => 'RESTRICT',
        // The Payment's *current* attempt pointer, not the attempt row
        // itself — nulling it on delete is correct (see PAYMENT domain's
        // own migration comment); the attempt row is independently
        // protected by payment_attempts.payment_id below.
        'current_payment_attempt_id' => 'SET NULL',
    ]);
});

it('enforces RESTRICT at the database level for payment_attempts.payment_id', function () {
    expect(financialForeignKeyDeleteRules('payment_attempts'))->toMatchArray([
        'payment_id' => 'RESTRICT',
    ]);
});

it('never lets any Payments/Wallet-side financial foreign key use CASCADE at the database level', function () {
    $offenders = [];

    $tables = ['stores', 'store_wallets', 'store_wallet_transactions', 'orders', 'payments', 'payment_attempts'];

    foreach ($tables as $table) {
        foreach (financialForeignKeyDeleteRules($table) as $column => $rule) {
            if ($rule === 'CASCADE') {
                $offenders[] = "{$table}.{$column}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
