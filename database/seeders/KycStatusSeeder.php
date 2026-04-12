<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KycStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $kycStatuses = [
            ['name' => 'Pending', 'slug' => 'pending', 'color' => '#FBBF24', 'is_active' => true],
            ['name' => 'Approved', 'slug' => 'approved', 'color' => '#10B981', 'is_active' => true],
            ['name' => 'Rejected', 'slug' => 'rejected', 'color' => '#EF4444', 'is_active' => true],
            ['name' => 'Under Review', 'slug' => 'under-review', 'color' => '#3B82F6', 'is_active' => true],
            ['name' => 'Expired', 'slug' => 'expired', 'color' => '#9CA3AF', 'is_active' => true],
        ];

        foreach ($kycStatuses as $status) {
            DB::table('kyc_status')->updateOrInsert(
                ['slug' => $status['slug']], // Unique identifier for update
                array_merge($status, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
