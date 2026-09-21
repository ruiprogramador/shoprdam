<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/catalog-domain` — see docs/catalog/CATALOG-DOMAIN.md. Establishes
 * `Product` as the minimum, real, persisted sellable-unit identity: a
 * Product belongs to exactly one Store, has one exact price in one explicit
 * currency, and a visibility flag independent of any inventory concept
 * (inventory does not exist yet — see that document's own non-goals).
 *
 * Deliberately no `slug`, `sku`, `category_id`, `description`, images,
 * discount/old_price, rating/review, or variant/option columns — none has
 * repository evidence requiring it (see the branch's own discovery audit).
 * Adding any of those later is an additive migration, not a rework of this
 * one.
 *
 * FK policy follows the CROSS-14 discipline exactly
 * (docs/financial/INVARIANTS.md): every FK here is `restrictOnDelete()`,
 * never `cascadeOnDelete()`. A Store or Currency with any Product history
 * can never be deleted out from under it. `SoftDeletes` mirrors `stores`'
 * own precedent (`2026_07_02_063758_create_stores_table.php`) — a
 * deactivated/removed Product's row survives, so nothing that comes to
 * reference it later (an `OrderItem`, an inventory reservation) can ever
 * find a hole where a real historical record used to be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Ownership — see App\Domain\Catalog\Models\Product's own
            // performUpdate() guard for why this column, once set at
            // creation, can never change through any production path.
            $table->foreignId('store_id')->constrained()->restrictOnDelete();

            $table->string('name');

            // Exact money: decimal(18,2), identical precision/scale to
            // orders.amount, payouts.amount, store_wallet_transactions.amount
            // — the one canonical money representation this codebase uses
            // everywhere, never a float. Commercial intent only — this
            // column is never read by, or written from, any Wallet/Payment
            // code (docs/catalog/CATALOG-DOMAIN.md, CATALOG-05/06).
            $table->decimal('price_amount', 18, 2);

            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            // Catalog VISIBILITY only — "may be offered for new commercial
            // activity" — never inventory availability, which this branch
            // does not define at all (docs/catalog/CATALOG-DOMAIN.md, CATALOG-07).
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // The natural query shape for a Store's own catalog listing —
            // mirrors orders' own (store_id, order_status_id) index for the
            // same reason.
            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
