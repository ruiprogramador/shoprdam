<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes CROSS-14 (docs/financial/INVARIANTS.md): the Payments/Wallet side
 * of the financial graph is the last place still using cascadeOnDelete()
 * for financial history, unlike Payouts (already restrictOnDelete() end to
 * end — see PayoutSchemaDeletePolicyTest). This mirrors that same pattern
 * onto the six FKs docs/financial/FAILURE-MODEL.md's "Critical finding"
 * names as the live, unguarded path from a vendor's self-service account
 * deletion down to permanent ledger destruction:
 *
 *   stores.user_id -> store_wallets.store_id -> store_wallet_transactions.store_wallet_id
 *   orders.store_id -> payments.order_id -> payment_attempts.payment_id
 *
 * Every one of these becomes restrictOnDelete(): the database now refuses
 * to delete a User/Store/StoreWallet/Order/Payment while any of these
 * financial-history children still reference it, instead of silently
 * cascading the deletion through the whole chain.
 *
 * Schema-only change: every column, index, and row is left exactly as it
 * is — only the six FK constraints themselves are dropped and re-added with
 * a different ON DELETE rule. No table is dropped/recreated, so this is
 * safe to run against a database that already holds real financial history.
 *
 * Rollback (down()) restores the original cascadeOnDelete() behavior. This
 * is schema-only in the same way — it does NOT delete or alter any existing
 * row — but it is NOT a claim that rolling back is financially safe: doing
 * so genuinely reopens the exact CROSS-14 vulnerability this migration
 * closes for any deletion performed after the rollback. down() exists
 * because this project's migration convention requires one and a rollback
 * must never corrupt data, not because reverting the guarantee is
 * recommended. See
 * tests/Feature/Domain/Payments/FinancialHistoryCascadeMigrationSafetyTest
 * for the test that proves both halves of this: existing rows survive
 * down()+up(), and the live FK policy genuinely flips back to CASCADE on
 * down() and back to RESTRICT on up().
 */
return new class extends Migration
{
    /**
     * The financial FKs being tightened, in child-safe order (a child is
     * altered before code ever assumes its parent is protected).
     *
     * @var array<int, array{table: string, column: string, references: string, on: string}>
     */
    private array $foreignKeys = [
        ['table' => 'payment_attempts', 'column' => 'payment_id', 'references' => 'id', 'on' => 'payments'],
        ['table' => 'payments', 'column' => 'order_id', 'references' => 'id', 'on' => 'orders'],
        ['table' => 'store_wallet_transactions', 'column' => 'store_wallet_id', 'references' => 'id', 'on' => 'store_wallets'],
        ['table' => 'orders', 'column' => 'store_id', 'references' => 'id', 'on' => 'stores'],
        ['table' => 'store_wallets', 'column' => 'store_id', 'references' => 'id', 'on' => 'stores'],
        ['table' => 'stores', 'column' => 'user_id', 'references' => 'id', 'on' => 'users'],
    ];

    public function up(): void
    {
        foreach ($this->foreignKeys as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk) {
                $table->dropForeign([$fk['column']]);
                $table->foreign($fk['column'])
                    ->references($fk['references'])
                    ->on($fk['on'])
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->foreignKeys) as $fk) {
            Schema::table($fk['table'], function (Blueprint $table) use ($fk) {
                $table->dropForeign([$fk['column']]);
                $table->foreign($fk['column'])
                    ->references($fk['references'])
                    ->on($fk['on'])
                    ->cascadeOnDelete();
            });
        }
    }
};
