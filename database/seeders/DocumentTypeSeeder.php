<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Passport',
            'National Identity Card',
            'Driving Licence',
            'Residence Permit',
            'Tax Identification Card',
        ];

        foreach ($types as $name) {
            DocumentType::updateOrInsert(
                ['slug' => Str::slug($name)],
                [
                    'name'       => $name,
                    'slug'       => Str::slug($name),
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('✓ Document types seeded: ' . DocumentType::count() . ' records.');
    }
}