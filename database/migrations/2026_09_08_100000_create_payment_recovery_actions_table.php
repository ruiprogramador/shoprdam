<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail for App\Http\Controllers\Admin\PaymentRecoveryController —
 * one durable row per manual recovery attempt an admin triggers, mirroring
 * `kyc_history`'s role for KYC review decisions. Every column here is
 * already non-sensitive: `outcome`/`detail` are the same
 * RecoveryOutcome/exception-message vocabulary
 * App\Domain\Payments\Services\PaymentAttemptRecoveryService and
 * PaymentEventProcessor already log — never a raw provider payload, secret,
 * or card datum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_recovery_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_attempt_id')->constrained()->cascadeOnDelete();

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
