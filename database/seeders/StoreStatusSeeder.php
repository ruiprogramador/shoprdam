<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class StoreStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            ['name' => 'Draft',   'slug' => 'draft',   'color' => '#6B7280', 'sort_order' => 10, 'description' => 'Store is in draft mode and not visible to the public'],
            ['name' => 'Pending Review',   'slug' => 'pending-review',   'color' => '#FBBF24', 'sort_order' => 20, 'description' => 'Store awaiting admin verification'],
            ['name' => 'Active',    'slug' => 'active',    'color' => '#10B981', 'sort_order' => 30, 'description' => 'Store verified and publicly visible'],
            ['name' => 'Suspended', 'slug' => 'suspended', 'color' => '#EF4444', 'sort_order' => 40, 'description' => 'Store suspended by admin'],
        ];

        foreach ($statuses as $status) {
            DB::table('store_statuses')->updateOrInsert(
                ['slug' => $status['slug']],
                array_merge($status, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
