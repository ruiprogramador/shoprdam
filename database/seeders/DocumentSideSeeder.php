<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentSideSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $documentSides = [
            ['name' => 'Front', 'slug' => 'front'],
            ['name' => 'Back', 'slug' => 'back'],
            ['name' => 'Selfie', 'slug' => 'selfie'],
            ['name' => 'Liveness video', 'slug' => 'liveness_video'],
        ];

        foreach ($documentSides as $side) {
            DB::table('document_sides')->updateOrInsert(
                ['slug' => $side['slug']], // Unique identifier for update
                array_merge($side, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
