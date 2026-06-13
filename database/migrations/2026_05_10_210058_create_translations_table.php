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
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('context')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('translation_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('translation_id')
                ->constrained('translations')
                ->cascadeOnDelete();

            $table->string('locale', 10);

            $table->string('value_short')->nullable();
            $table->text('value')->nullable();
            $table->longText('value_long')->nullable();
            $table->longText('value_html')->nullable();

            $table->enum('status', ['missing', 'auto', 'done'])
                ->default('missing');

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();

            $table->timestamp('translated_at')->nullable();

            $table->timestamps();

            $table->unique(['translation_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('translation_values');
        Schema::dropIfExists('translations');
    }
};
