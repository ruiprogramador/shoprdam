<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/inventory-reservations` — see docs/inventory/INVENTORY-RESERVATIONS.md.
 * One Inventory row per Product: the smallest persisted sellable identity
 * (there is no Variant/SKU anywhere in the repository).
 *
 * ## Source of truth
 *
 * Two mutable counters, both authoritative for what they mean, and one
 * derived value that is never stored:
 *
 *   on_hand_quantity   physical units present and not yet consumed
 *   reserved_quantity  units held by `reserved` InventoryReservations
 *   available          on_hand_quantity - reserved_quantity   (derived)
 *
 * `reserved_quantity` is a maintained aggregate of the live reservation rows,
 * not an independent fact: it changes only in the same transaction, and by the
 * same quantity, as a reservation state transition
 * (App\Domain\Inventory\Services\InventoryReservationService), and
 * App\Domain\Inventory\Services\InventoryIntegrityChecker detects any drift
 * between the two. It is never auto-repaired.
 *
 * ## Signed columns, on purpose
 *
 * Deliberately NOT `unsigned`: MySQL evaluates `unsigned - unsigned` as
 * BIGINT UNSIGNED and raises "value is out of range" instead of yielding a
 * negative number, which would turn the availability predicate
 * (`on_hand_quantity - reserved_quantity >= ?`) into an error rather than a
 * refusal. Non-negativity is a CHECK constraint instead.
 *
 * ## Constraints
 *
 * unique(product_id); FK RESTRICT (CROSS-14 — never CASCADE, an Inventory row
 * is history-bearing); CHECK on_hand >= 0, reserved >= 0, reserved <=
 * on_hand. The last one is the database backstop of INVENTORY-01: even a
 * buggy writer cannot make available stock negative. On MySQL/MariaDB/
 * PostgreSQL these are real named CHECK constraints (MySQL enforces CHECK only
 * from 8.0.16, MariaDB from 10.2.1 — older servers parse and silently ignore
 * it); on SQLite (dev and test engine) they are BEFORE INSERT/UPDATE
 * triggers, as in create_order_items_table. Only the SQLite form is
 * exercised by this repository's tests.
 *
 * No SoftDeletes, no warehouse/location/lot/serial/supplier/backorder column —
 * none has repository evidence requiring it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unique('product_id');

            $table->bigInteger('on_hand_quantity');
            $table->bigInteger('reserved_quantity')->default(0);

            $table->timestamps();
        });

        $this->addChecks('inventories', [
            'inventories_on_hand_non_negative' => '{r}on_hand_quantity >= 0',
            'inventories_reserved_non_negative' => '{r}reserved_quantity >= 0',
            'inventories_reserved_within_on_hand' => '{r}reserved_quantity <= {r}on_hand_quantity',
        ], sqliteIntegerColumns: ['on_hand_quantity', 'reserved_quantity']);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
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
