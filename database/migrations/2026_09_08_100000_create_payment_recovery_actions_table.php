<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for App\Http\Controllers\Admin\PaymentRecoveryController —
 * one durable row per manual recovery attempt an admin triggers, mirroring
 * `kyc_history`'s role for KYC review decisions. `outcome`/`detail` are
 * never a raw provider payload, secret, or card datum — see
 * App\Domain\Payments\RecoveryErrorFormatter, which every `detail` value
 * passes through before it's ever written here.
 *
 * `payment_attempt_id` deliberately restricts, not cascades, on delete:
 * nothing in this domain ever deletes a PaymentAttempt (they're append-only
 * financial history — see that model's own docblock), so this is a
 * belt-and-suspenders invariant, not a real-world scenario. But if that
 * ever changed, audit evidence must never silently disappear alongside the
 * record it evidences; restricting the delete instead of cascading it means
 * a PaymentAttempt with recovery history physically cannot be deleted at
 * all, which is the correct failure mode for financial audit trail rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_recovery_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->restrictOnDelete();

            // Nullable: an admin account being deleted later must never
            // delete the audit row proving what was done — only who is
            // forgotten.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();

            $table->string('action', 40);
            $table->string('outcome', 40);
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->index(['payment_attempt_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_recovery_actions');
    }
};
