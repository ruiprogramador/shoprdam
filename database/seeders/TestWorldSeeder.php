<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestWorldSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('countries')->upsert([
            ['id' => 177, 'iso2' => 'PT', 'name' => 'Portugal', 'status' => 1, 'phone_code' => '351', 'iso3' => 'PRT', 'region' => 'Europe', 'subregion' => 'Southern Europe'],
            ['id' => 233, 'iso2' => 'US', 'iso3' => 'USA', 'name' => 'United States', 'phone_code' => '1', 'region' => 'Americas', 'status' => 1, 'subregion' => 'Northern America'],
        ], ['id']);

        DB::table('states')->upsert([
            ['id' => 3282, 'country_id' => 177, 'name' => 'Coimbra', 'country_code' => 'PT'],
        ], ['id']);

        DB::table('cities')->upsert([
            ['id' => 91876, 'country_id' => 177, 'state_id' => 3282, 'name' => 'Cantanhede', 'country_code' => 'PT'],
        ], ['id']);

        // ID : 2 - EUR, 148-USD
        DB::table('currencies')->upsert([
            ['id' => 2, 'country_id' => 177, 'name' => 'Euro', 'code' => 'EUR', 'precision' => 2, 'symbol' => '€', 'symbol_native' => '€', 'symbol_first' => 1, 'decimal_mark' => '.', 'thousands_separator' => ','],
            ['id' => 148, 'country_id' => 233, 'name' => 'US Dollar', 'code' => 'USD', 'precision' => 2, 'symbol' => '$', 'symbol_native' => '$', 'symbol_first' => 1, 'decimal_mark' => '.', 'thousands_separator' => ','],
        ], ['id']);
    }
}