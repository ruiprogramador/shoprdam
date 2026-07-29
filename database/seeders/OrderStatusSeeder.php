<?php

/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
|
| These records are referenced throughout the application by their slug.
| Existing slugs must never be changed in production.
| New order statuses may be added in future releases.
|
*/

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrderStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            ['name' => 'Pending',  'slug' => 'pending',  'sort_order' => 10, 'description' => 'Order awaiting payment'],
            ['name' => 'Paid',     'slug' => 'paid',     'sort_order' => 20, 'description' => 'Order payment succeeded'],
            ['name' => 'Failed',   'slug' => 'failed',   'sort_order' => 30, 'description' => 'Order payment failed'],
            ['name' => 'Refunded', 'slug' => 'refunded', 'sort_order' => 40, 'description' => 'Order payment was refunded'],
        ];

        $timestamp = now();

        foreach ($statuses as $status) {
            DB::table('order_statuses')->updateOrInsert(
                ['slug' => $status['slug']],
                array_merge($status, ['is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp])
            );
        }
    }
}
