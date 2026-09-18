<?php

use Illuminate\Support\Facades\DB;

/**
 * Verifies the actual, migrated FK delete policy for
 * payment_reconciliation_findings against real `PRAGMA foreign_key_list`
 * output — mirrors tests/Feature/Domain/Payments/PaymentsSchemaDeletePolicyTest
 * exactly. See docs/financial/RECONCILIATION.md §18/§19/§20 for why
 * payment_attempt_id must restrict (never cascade — audit evidence must
 * never silently disappear or lose its own subject) and acknowledged_by
 * must null-on-delete (an admin being deleted must never delete the fact
 * that someone acknowledged a finding).
 */
function reconciliationFindingForeignKeyDeleteRules(): array
{
    return collect(DB::select("PRAGMA foreign_key_list('payment_reconciliation_findings')"))
        ->mapWithKeys(fn ($row) => [$row->from => $row->on_delete])
        ->all();
}

it('enforces RESTRICT at the database level for payment_attempt_id', function () {
    expect(reconciliationFindingForeignKeyDeleteRules())->toMatchArray([
        'payment_attempt_id' => 'RESTRICT',
    ]);
});

it('enforces SET NULL at the database level for acknowledged_by', function () {
    expect(reconciliationFindingForeignKeyDeleteRules())->toMatchArray([
        'acknowledged_by' => 'SET NULL',
    ]);
});

it('never uses CASCADE for any foreign key on payment_reconciliation_findings', function () {
    $offenders = collect(reconciliationFindingForeignKeyDeleteRules())
        ->filter(fn ($rule) => $rule === 'CASCADE')
        ->keys()
        ->all();

    expect($offenders)->toBe([]);
});

it('enforces uniqueness on active_identity at the database level', function () {
    $indexes = collect(DB::select("PRAGMA index_list('payment_reconciliation_findings')"))
        ->filter(fn ($index) => (bool) $index->unique);

    $uniqueColumns = $indexes->map(function ($index) {
        return collect(DB::select("PRAGMA index_info('{$index->name}')"))->pluck('name')->all();
    });

    expect($uniqueColumns->contains(['active_identity']))->toBeTrue();
});
