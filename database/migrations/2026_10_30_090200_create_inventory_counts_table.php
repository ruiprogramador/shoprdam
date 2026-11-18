<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/inventory-control` — see docs/inventory/INVENTORY-CONTROL.md §4.3, §7, §16.
 *
 * One physical-count observation per row, including those that change nothing:
 * a `stale` snapshot or a shortage inside reserved stock is preserved as
 * evidence, never rejected without trace.
 *
 * It keeps apart what was observed (`observed_quantity`, against the snapshot
 * `expected_on_hand`) and what the authoritative state was when the observation
 * was applied (`on_hand_at_submission`, `reserved_at_submission`, read under the
 * Inventory row lock). The status CHECK makes the stored facts imply the status,
 * so a row whose status contradicts its own quantities cannot be stored.
 *
 * Append-once, except the one compare-and-set
 * `discrepancy_open -> discrepancy_resolved`. `(inventory_id, status)` is the
 * foreign key's supporting index, so that status change must run under the
 * Inventory row lock, as every writer here does.
 *
 * Implemented, NOT activated: STOCK-22. `actor_user_id` is deliberately not a
 * foreign key (§4.5). Every FK is RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->string('idempotency_key', 96);
            $table->unique(['inventory_id', 'idempotency_key'], 'inventory_counts_idempotency_unique');

            $table->bigInteger('expected_on_hand');
            $table->bigInteger('observed_quantity');
            $table->bigInteger('on_hand_at_submission');
            $table->bigInteger('reserved_at_submission');

            $table->string('status', 24);
            $table->foreignId('resolved_by_count_id')->nullable()->constrained('inventory_counts')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id');
            $table->text('note')->nullable();

            $table->timestamp('created_at');

            $table->index(['inventory_id', 'status']);
        });

        $this->addChecks('inventory_counts', [
            'inventory_counts_quantities_valid' => '{r}expected_on_hand >= 0 AND {r}observed_quantity >= 0'
                .' AND {r}on_hand_at_submission >= 0 AND {r}reserved_at_submission >= 0'
                .' AND {r}reserved_at_submission <= {r}on_hand_at_submission',
            'inventory_counts_status_evidence' => "({r}status = 'stale' AND {r}expected_on_hand <> {r}on_hand_at_submission"
                .' AND {r}resolved_by_count_id IS NULL AND {r}resolved_at IS NULL)'
                ." OR ({r}status = 'applied' AND {r}expected_on_hand = {r}on_hand_at_submission"
                .' AND {r}observed_quantity >= {r}reserved_at_submission'
                .' AND {r}resolved_by_count_id IS NULL AND {r}resolved_at IS NULL)'
                ." OR ({r}status = 'discrepancy_open' AND {r}expected_on_hand = {r}on_hand_at_submission"
                .' AND {r}observed_quantity < {r}reserved_at_submission'
                .' AND {r}resolved_by_count_id IS NULL AND {r}resolved_at IS NULL)'
                ." OR ({r}status = 'discrepancy_resolved' AND {r}expected_on_hand = {r}on_hand_at_submission"
                .' AND {r}observed_quantity < {r}reserved_at_submission'
                .' AND {r}resolved_by_count_id IS NOT NULL AND {r}resolved_at IS NOT NULL)',
            'inventory_counts_actor' => "{r}actor_type = 'store_owner'",
        ], sqliteIntegerColumns: ['expected_on_hand', 'observed_quantity', 'on_hand_at_submission', 'reserved_at_submission', 'actor_user_id']);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counts');
    }

    /**
     * @param  array<string, string>  $checks  constraint name => predicate, with `{r}` standing for the row prefix
     * @param  list<string>  $sqliteIntegerColumns  SQLite columns that must hold a real INTEGER (its typing is otherwise flexible)
     */
    private function addChecks(string $table, array $checks, array $sqliteIntegerColumns = []): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            foreach ($checks as $name => $predicate) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK (".str_replace('{r}', '', $predicate).')');
            }

            return;
        }

        $typed = array_map(fn (string $c) => "typeof(NEW.{$c}) <> 'integer'", $sqliteIntegerColumns);

        foreach ($checks as $name => $predicate) {
            $violation = implode(' OR ', [...$typed, 'NOT ('.str_replace('{r}', 'NEW.', $predicate).')']);

            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$name}_{$event}
                    BEFORE {$event} ON {$table}
                    WHEN {$violation}
                    BEGIN
                        SELECT RAISE(ABORT, '{$name}');
                    END
                SQL);
            }
        }
    }
};
