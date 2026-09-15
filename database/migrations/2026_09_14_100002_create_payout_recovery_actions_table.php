<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for App\Http\Controllers\Admin\PayoutRecoveryController —
 * one durable row per manual recovery/confirmation action an admin
 * triggers, mirroring payment_recovery_actions exactly. `outcome`/`detail`
 * never carry a raw provider exception message or secret — see
 * App\Domain\Payments\RecoveryErrorFormatter, reused as-is here.
 *
 * `metadata` additionally captures the manual-confirmation payload an
 * operator submitted (external_transfer_reference, amount, currency,
 * executed_at) as historical evidence of what was claimed at the time —
 * this row is never updated again after finishAction() writes it once, so
 * it survives even if the attempt's own columns were ever disputed later.
 *
 * `payout_attempt_id` restricts, not cascades: payout financial history is
 * append-only (see tests/Architecture/PayoutFinancialHistoryAppendOnlyTest)
 * — an attempt with recovery history physically cannot be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_recovery_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_attempt_id')->constrained()->restrictOnDelete();

            // Nullable: an admin account being deleted later must never
            // delete the audit row proving what was done — only who is
            // forgotten.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();

            $table->string('action', 40);
            $table->string('outcome', 40);
            $table->text('detail')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['payout_attempt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_recovery_actions');
    }
};
