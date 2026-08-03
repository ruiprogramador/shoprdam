<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_payment_intent_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 255);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('recovery_attempts')->default(0);
            $table->timestamp('last_recovery_attempted_at')->nullable();
            $table->text('last_recovery_error')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_payment_intent_attempts');
    }
};
