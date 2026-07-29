<?php

/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
|
| These records are referenced throughout the application by their slug.
| Existing slugs must never be changed in production.
| New transaction statuses may be added in future releases.
|
*/

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class TransactionStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            ['name' => 'Pending',   'slug' => 'pending',   'sort_order' => 10, 'description' => 'Transaction awaiting processing'],
            ['name' => 'Completed', 'slug' => 'completed', 'sort_order' => 20, 'description' => 'Transaction successfully processed'],
            ['name' => 'Failed',    'slug' => 'failed',    'sort_order' => 30, 'description' => 'Transaction processing failed'],
            ['name' => 'Cancelled', 'slug' => 'cancelled', 'sort_order' => 40, 'description' => 'Transaction was cancelled'],
            ['name' => 'Reversed',  'slug' => 'reversed',  'sort_order' => 50, 'description' => 'Transaction was reversed'],
        ];

        $timestamp = now();

        foreach ($statuses as $status) {
            DB::table('transaction_statuses')->updateOrInsert(
                ['slug' => $status['slug']],
                array_merge($status, ['is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp])
            );
        }
    }
}
