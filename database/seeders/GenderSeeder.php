<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GenderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $genders = [
            ['name' => 'Male', 'slug' => 'male'],
            ['name' => 'Female', 'slug' => 'female'],
            ['name' => 'Prefer not to disclose', 'slug' => 'prefer_not_to_disclose'],
            ['name' => 'Custom', 'slug' => 'custom'],
        ];

        foreach ($genders as $gender) {
            DB::table('genders')->updateOrInsert(
                ['slug' => $gender['slug']],
                array_merge($gender, [
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
