<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('translations_old')) {
            return;
        }

        DB::table('translations_old')
            ->orderBy('id')
            ->chunk(500, function ($rows) {

                foreach ($rows as $row) {

                    if (!$row->group || !$row->key) {
                        continue;
                    }

                    $key = "{$row->group}.{$row->key}";

                    $translationId = DB::table('translations')
                        ->where('key', $key)
                        ->value('id');

                    if (!$translationId) {
                        $translationId = DB::table('translations')->insertGetId([
                            'key' => $key,
                            'label' => Str::of($key)->replace('.', ' ')->title(),
                            'context' => null,
                            'is_system' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('translation_values')->updateOrInsert(
                        [
                            'translation_id' => $translationId,
                            'locale' => $row->locale,
                        ],
                        [
                            'value_short' => $row->text_short,
                            'value' => $row->text,
                            'value_long' => $row->text_long,
                            'value_html' => $row->text_html,
                            'status' => 'done',
                            'updated_by' => $row->updated_by,
                            'translated_at' => $row->updated_at,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ]
                    );
                }
            });
    }

    public function down(): void
    {
        /* DB::table('translation_values')->truncate();
        DB::table('translations')->truncate(); */
        throw new Exception('Data migration is irreversible.');
    }
};