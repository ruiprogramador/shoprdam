<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();

            $table->string('locale', 10)->index();
            $table->string('group')->index();
            $table->string('key')->index();

            // Text variants
            $table->string('text_short')->nullable();
            $table->text('text')->nullable();           
            $table->longText('text_long')->nullable(); 
            $table->longText('text_html')->nullable();

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Audit trail
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->foreign('created_by')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('admins')->nullOnDelete();

            // Unique constraint
            $table->unique(['locale', 'group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};