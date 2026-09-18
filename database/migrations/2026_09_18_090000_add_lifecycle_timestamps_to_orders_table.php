<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only — see docs/financial/ORDER-LIFECYCLE.md §7. Adds a timestamp
 * for exactly the two Order states whose entry is a stable, once-only fact
 * written by the canonical transition path
 * (App\Domain\Orders\Services\OrderLifecycleService): `paid_at` (entered
 * `paid`) and `refunded_at` (entered `refunded`).
 *
 * Deliberately no `failed_at`: `failed` means "the latest payment attempt
 * failed" and can be re-entered, so a single timestamp would have no stable
 * meaning. Deliberately nothing for fulfillment/cancellation states — those
 * states do not exist (docs/financial/ORDER-LIFECYCLE.md §3).
 *
 * Existing rows keep NULL: nothing in the database records when a historical
 * Order actually became `paid`/`refunded` (`updated_at` moves on any write),
 * and inventing a value from it would be fabricating history.
 *
 * No foreign key is added or altered — orders.store_id and every other
 * financial FK stay exactly as CROSS-14 left them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'refunded_at']);
        });
    }
};
