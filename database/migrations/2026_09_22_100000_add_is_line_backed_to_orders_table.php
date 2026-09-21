<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `feat/order-items` — persistent provenance. See docs/orders/ORDER-ITEMS.md §4.
 *
 * `is_line_backed` records HOW an Order came to exist, not whether it has
 * OrderItem rows right now: `true` means "created by
 * App\Domain\Orders\Services\OrderCreationService, whose Order.amount is
 * derived from its immutable OrderItems". It never changes after creation.
 * Inferring this from "has at least one OrderItem" was fail-open — a
 * canonically created Order that lost every line to a raw write would have
 * looked like a legacy Order.
 *
 * NOT NULL DEFAULT false is the whole backfill decision: every Order that
 * exists when this migration runs (and every Order the factories and the dev
 * tool create afterwards) is unambiguously *legacy*. Nothing can be
 * inferred about their contents, so none is claimed; no OrderItem is created
 * and no existing row is touched beyond receiving the default. The only
 * writer of `true` is OrderCreationService, in the same transaction as the
 * Order's lines. No index: the column is never a query predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_line_backed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_line_backed');
        });
    }
};
