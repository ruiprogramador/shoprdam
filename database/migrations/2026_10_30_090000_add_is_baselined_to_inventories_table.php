<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/inventory-control` — see docs/inventory/INVENTORY-CONTROL.md §8.4.
 *
 * The lockable projection of "a baseline row exists for this Inventory". A
 * writer reads it from the Inventory row it already holds exclusively, so
 * neither a plain read of another table nor a gap-locking read of
 * `inventory_baselines` is ever needed to stamp a movement.
 *
 * Follows the `orders.is_line_backed` precedent: NOT NULL DEFAULT false, so
 * every row that exists when this runs is unambiguously unevidenced legacy.
 * That is not a backfill: `false` asserts only that no evidence exists, which
 * is true of every such row. Only InventoryControlService writes `true`, at
 * creation or through adopt()'s compare-and-set, and nothing resets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->boolean('is_baselined')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('is_baselined');
        });
    }
};
