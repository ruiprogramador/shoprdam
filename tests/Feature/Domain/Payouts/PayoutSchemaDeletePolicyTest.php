<?php

use Illuminate\Support\Facades\DB;

/**
 * Complements tests/Architecture/PayoutFinancialHistoryAppendOnlyTest's
 * text-based scan of the migration source with a check of the *actual*
 * constraint SQLite enforces, read straight from the migrated schema via
 * `PRAGMA foreign_key_list`. The text-based scan only ever recognizes
 * Laravel's `foreignId()->constrained()->xOnDelete()` fluent style — this
 * test is immune to that specific bypass (e.g. a FK declared via the older
 * `$table->foreign('col')->references()->on()->onDelete()` API would still
 * be caught here, since it inspects the real, effective database behavior
 * regardless of which PHP API produced it).
 *
 * This is a Feature test, not an Architecture one, precisely because it
 * needs a real migrated database — see tests/Pest.php for why Architecture
 * tests deliberately don't get one.
 */
function foreignKeyDeleteRules(string $table): array
{
    return collect(DB::select("PRAGMA foreign_key_list('{$table}')"))
        ->mapWithKeys(fn ($row) => [$row->from => $row->on_delete])
        ->all();
}

it('enforces RESTRICT at the database level for every financial foreign key on payouts', function () {
    $rules = foreignKeyDeleteRules('payouts');

    expect($rules)->toMatchArray([
        'store_id' => 'RESTRICT',
        'store_wallet_id' => 'RESTRICT',
        'currency_id' => 'RESTRICT',
        'debit_transaction_id' => 'RESTRICT',
        'current_payout_attempt_id' => 'RESTRICT',
        // Non-financial identity — deliberately not restrict; see the
        // migration's own comment.
        'requested_by' => 'SET NULL',
    ]);
});

it('enforces RESTRICT at the database level for payout_attempts.payout_id', function () {
    expect(foreignKeyDeleteRules('payout_attempts'))->toMatchArray([
        'payout_id' => 'RESTRICT',
    ]);
});

it('enforces RESTRICT at the database level for payout_recovery_actions.payout_attempt_id', function () {
    expect(foreignKeyDeleteRules('payout_recovery_actions'))->toMatchArray([
        'payout_attempt_id' => 'RESTRICT',
        // Non-financial identity — deliberately not restrict.
        'admin_id' => 'SET NULL',
    ]);
});

it('never lets any payout-related foreign key use CASCADE at the database level', function () {
    $offenders = [];

    foreach (['payouts', 'payout_attempts', 'payout_recovery_actions'] as $table) {
        foreach (foreignKeyDeleteRules($table) as $column => $rule) {
            if ($rule === 'CASCADE') {
                $offenders[] = "{$table}.{$column}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
