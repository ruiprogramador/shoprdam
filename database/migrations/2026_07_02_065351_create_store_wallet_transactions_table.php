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
        Schema::create('store_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('store_wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_status_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->string('description')->nullable();
            $table->string('external_provider', 50)->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->string('referenceable_type')->nullable();
            $table->unsignedBigInteger('referenceable_id')->nullable();
            $table->foreignId('related_transaction_id')->nullable()->constrained('store_wallet_transactions')->nullOnDelete()->comment('Links reversal/refund transactions');
            $table->json('metadata')->nullable()->comment('Additional provider-specific information');
            $table->string('source', 20)->default('system');
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['external_provider', 'external_reference'],
                'external_ref_idx'
            );
            $table->index(
                ['store_wallet_id', 'transaction_status_id'],
                'wallet_status_idx'
            );
            
            $table->index(
                ['store_wallet_id', 'created_at'],
                'wallet_created_idx'
            );

            $table->index(['referenceable_type', 'referenceable_id'], 'referenceable_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_wallet_transactions');
    }
};
