<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/inventory-reservations` — see docs/inventory/INVENTORY-RESERVATIONS.md.
 * One reservation per OrderItem: the ownership chain
 * Product -> Inventory -> InventoryReservation <- OrderItem <- Order is
 * unambiguous, and `unique(order_item_id)` is the logical idempotency
 * identity of "reserve this line" — a retry can never hold stock twice.
 *
 * `quantity` is copied from the OrderItem once, at reservation time. It is
 * duplicated deliberately (not for convenience): release/commit must undo
 * EXACTLY what reserve added to `inventories.reserved_quantity`, even if
 * the OrderItem row is later tampered with by a raw write, and the integrity
 * checker compares the two to detect precisely that. No product_id is stored
 * — it is reachable through inventory_id and order_item_id, whose agreement
 * the checker also verifies.
 *
 * Lifecycle: `reserved` -> `committed` | `released`, both terminal. There is
 * no `expired` state and no `expires_at`: automatic expiry is deliberately
 * not implemented, because releasing a reservation whose Payment can still
 * succeed at the provider creates an unresolved financial/commercial
 * contradiction (see the design document, §12). Adding it later is an
 * additive migration.
 *
 * FK policy is CROSS-14: every FK is RESTRICT, never CASCADE — the database
 * refuses to delete an Inventory row or an OrderItem (or, transitively, an
 * Order/Product) that any reservation still references. Terminal rows are
 * evidence and are never deleted. No SoftDeletes.
 *
 * The combined status/timestamp CHECK makes a terminal row carry its own
 * evidence (`committed_at` XOR `released_at` matching the status), so a
 * half-written terminal transition cannot be stored. CHECK vs. trigger
 * caveats are those of create_inventories_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->unique('order_item_id');

            $table->integer('quantity');
            $table->string('status', 16);

            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // Counter reconciliation: SUM(quantity) of live reservations per inventory.
            $table->index(['inventory_id', 'status']);
        });

        $this->addChecks('inventory_reservations', [
            'inventory_reservations_quantity_positive' => '{r}quantity >= 1',
            'inventory_reservations_status_evidence' => "({r}status = 'reserved' AND {r}committed_at IS NULL AND {r}released_at IS NULL)"
                ." OR ({r}status = 'committed' AND {r}committed_at IS NOT NULL AND {r}released_at IS NULL)"
                ." OR ({r}status = 'released' AND {r}released_at IS NOT NULL AND {r}committed_at IS NULL)",
        ], sqliteIntegerColumns: ['quantity']);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
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
