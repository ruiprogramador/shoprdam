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
        Schema::create('kyc_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_id')->constrained('kycs')->onDelete('cascade');
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('kyc_status')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('kyc_status')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['kyc_id', 'from_status_id', 'to_status_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_history');
    }
};
