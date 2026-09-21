<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/order-items` — see docs/orders/ORDER-ITEMS.md. An OrderItem is the
 * immutable historical commercial snapshot of one Product line inside one
 * Order: which Product, its name and unit price and currency AS AGREED at
 * order creation, and the quantity.
 *
 * Deliberately no sku, variant, tax, discount, shipping, status, inventory,
 * reservation, fulfillment, supplier, image, slug or metadata column — none
 * has repository evidence requiring it. No `line_total_amount` either: it is
 * exactly `unit_price_amount * quantity` and is derived, never stored twice.
 *
 * Existing Orders are NOT backfilled. Nothing in the database records which
 * Product, name, quantity or unit price a historical Order actually
 * contained, and inventing them from `orders.amount` would be fabricating
 * history. Pre-existing Orders simply stay line-less ("legacy Orders").
 *
 * FK policy follows CROSS-14 exactly: every FK is `restrictOnDelete()`,
 * never `cascadeOnDelete()` — the database refuses to delete an Order, a
 * Product or a Currency that any OrderItem still references. This is also the
 * database backstop `feat/catalog-domain` (CATALOG-09) was waiting for.
 *
 * No unique(order_id, product_id): the canonical creation path merges
 * duplicate Products into one line itself, but a future line concept
 * (options, discounts) could legitimately repeat a Product in one Order, and
 * a unique index here would be a migration trap for that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // The snapshot: copied from the Product once, at order creation.
            // Never re-read from products afterwards.
            $table->string('product_name');
            $table->decimal('unit_price_amount', 18, 2);
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            // Positive integer. `unsignedInteger` documents intent, but
            // neither SQLite nor PostgreSQL enforces "unsigned" — the
            // `quantity >= 1` rule is added below as a real constraint
            // instead. Upper bound (2147483647, the portable signed 32-bit
            // ceiling) is enforced by the canonical creation service.
            $table->unsignedInteger('quantity');

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_id');
        });

        $this->addPositiveQuantityGuard();
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }

    /**
     * Laravel's Blueprint has no CHECK-constraint helper, and SQLite cannot
     * add a CHECK to an existing table, so this is necessarily driver
     * specific. On MySQL/MariaDB/PostgreSQL it is a real named CHECK
     * constraint; on SQLite (the engine of both the dev DB and the test DB)
     * it is a pair of BEFORE INSERT/UPDATE triggers that abort the statement.
     * Both reject the same rows; they are not the same mechanism, and only
     * the SQLite one is exercised by this repository's test suite.
     */
    private function addPositiveQuantityGuard(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_quantity_positive_insert
                BEFORE INSERT ON order_items
                WHEN NEW.quantity IS NULL OR typeof(NEW.quantity) <> 'integer' OR NEW.quantity < 1
                BEGIN
                    SELECT RAISE(ABORT, 'order_items.quantity must be a positive integer');
                END
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER order_items_quantity_positive_update
                BEFORE UPDATE OF quantity ON order_items
                WHEN NEW.quantity IS NULL OR typeof(NEW.quantity) <> 'integer' OR NEW.quantity < 1
                BEGIN
                    SELECT RAISE(ABORT, 'order_items.quantity must be a positive integer');
                END
            SQL);

            return;
        }

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity >= 1)');
    }
};
