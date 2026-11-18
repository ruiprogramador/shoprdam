<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/inventory-control` — see docs/inventory/INVENTORY-CONTROL.md §4.2, §5, §9.
 *
 * Append-only evidence of every change of `inventories.on_hand_quantity`, which
 * stays the authoritative current state: this table is never summed to decide
 * availability. A movement is written in the same transaction as the change it
 * evidences, by App\Domain\Inventory\Services\InventoryMovementWriter only.
 *
 * ## Identity
 *
 * `idempotency_key` is NOT NULL and `unique(inventory_id, idempotency_key)` is
 * the durable identity of every reason. No uniqueness here depends on NULL
 * semantics, which DATABASE-SUPPORT.md lists as unexercised per engine. That is
 * why `reservation_id` has no unique index of its own: on an engine treating
 * NULLs as equal, it would fail every second non-sale row.
 *
 * ## Correlation
 *
 * The CHECKs bind each reason to its sign, its one correlation column and its
 * actor; the correlation CHECK also rejects an unknown reason. `after_baseline`
 * is the Inventory's `is_baselined` read under this transaction's row lock. It
 * can be false only for a `sale`, because every other reason needs a baseline.
 *
 * `quantity_delta` is signed bigint: a count adjustment may span the whole
 * on-hand range. `actor_user_id` is deliberately not a foreign key (§4.5).
 * Every FK is RESTRICT, so history is never cascade-deleted. No SoftDeletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();

            $table->string('reason', 24);
            $table->bigInteger('quantity_delta');

            $table->string('idempotency_key', 96);
            $table->unique(['inventory_id', 'idempotency_key'], 'inventory_movements_idempotency_unique');

            $table->foreignId('reservation_id')->nullable()->constrained('inventory_reservations')->restrictOnDelete();
            $table->foreignId('count_id')->nullable()->constrained('inventory_counts')->restrictOnDelete();
            $table->foreignId('corrects_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();

            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('note')->nullable();

            $table->boolean('after_baseline');

            $table->timestamp('created_at');
        });

        $true = DB::connection()->getDriverName() === 'sqlite' ? '1' : 'true';

        $this->addChecks('inventory_movements', [
            'inventory_movements_delta_nonzero' => '{r}quantity_delta <> 0',
            'inventory_movements_reason_sign' => "({r}reason = 'receipt' AND {r}quantity_delta > 0)"
                ." OR ({r}reason IN ('sale', 'write_off') AND {r}quantity_delta < 0)"
                ." OR ({r}reason IN ('count_adjustment', 'correction') AND {r}quantity_delta <> 0)",
            'inventory_movements_correlation' => "({r}reason = 'sale' AND {r}reservation_id IS NOT NULL AND {r}count_id IS NULL AND {r}corrects_movement_id IS NULL)"
                ." OR ({r}reason = 'count_adjustment' AND {r}count_id IS NOT NULL AND {r}reservation_id IS NULL AND {r}corrects_movement_id IS NULL)"
                ." OR ({r}reason = 'correction' AND {r}corrects_movement_id IS NOT NULL AND {r}reservation_id IS NULL AND {r}count_id IS NULL)"
                ." OR ({r}reason IN ('receipt', 'write_off') AND {r}reservation_id IS NULL AND {r}count_id IS NULL AND {r}corrects_movement_id IS NULL)",
            'inventory_movements_actor' => "({r}reason = 'sale' AND {r}actor_type = 'system' AND {r}actor_user_id IS NULL)"
                ." OR ({r}reason <> 'sale' AND {r}actor_type = 'store_owner' AND {r}actor_user_id IS NOT NULL)",
            'inventory_movements_note_required' => "{r}reason NOT IN ('receipt', 'write_off', 'correction') OR {r}note IS NOT NULL",
            'inventory_movements_baseline_stamp' => "{r}reason = 'sale' OR {r}after_baseline = {$true}",
        ], sqliteIntegerColumns: ['quantity_delta', 'after_baseline']);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
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
