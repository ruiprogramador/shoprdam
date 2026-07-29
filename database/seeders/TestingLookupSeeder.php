<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TestingLookupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            KycStatusSeeder::class,
            GenderSeeder::class,
            TestWorldSeeder::class,
            StoreStatusSeeder::class,
            TransactionStatusSeeder::class,
            TransactionCategorySeeder::class,
            OrderStatusSeeder::class,
        ]);
    }
}
