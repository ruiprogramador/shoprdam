<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/inventory-control` — see docs/inventory/INVENTORY-CONTROL.md §4.4 and §8.
 *
 * Where an Inventory's evidence starts. At most one row per Inventory, written
 * once: `genesis` at canonical creation (0/0), or `adopted` for a legacy row,
 * which records the authoritative quantities at that moment and claims nothing
 * about what came before.
 *
 * `actor_user_id` is deliberately NOT a foreign key (§4.5): deleting a User
 * neither destroys nor mutates stock history. FK policy is otherwise CROSS-14:
 * RESTRICT, never CASCADE. CHECK vs. trigger caveats are those of
 * create_inventories_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_baselines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->unique('inventory_id', 'inventory_baselines_inventory_unique');

            $table->string('kind', 16);
            $table->bigInteger('on_hand_at_baseline');
            $table->bigInteger('reserved_at_baseline');

            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_user_id');
            $table->text('note')->nullable();

            $table->timestamp('created_at');
        });

        $this->addChecks('inventory_baselines', [
            'inventory_baselines_kind_evidence' => "({r}kind = 'genesis' AND {r}on_hand_at_baseline = 0 AND {r}reserved_at_baseline = 0)"
                ." OR ({r}kind = 'adopted' AND {r}on_hand_at_baseline >= 0 AND {r}reserved_at_baseline >= 0"
                .' AND {r}reserved_at_baseline <= {r}on_hand_at_baseline AND {r}note IS NOT NULL)',
            'inventory_baselines_actor' => "{r}actor_type = 'store_owner'",
        ], sqliteIntegerColumns: ['on_hand_at_baseline', 'reserved_at_baseline', 'actor_user_id']);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_baselines');
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
