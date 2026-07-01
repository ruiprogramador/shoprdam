<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestWorldSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('countries')->insert([
            ['id' => 177, 'iso2' => 'PT', 'name' => 'Portugal', 'status' => 1, 'phone_code' => '351', 'iso3' => 'PRT', 'region' => 'Europe', 'subregion' => 'Southern Europe'],
        ]);

        DB::table('states')->insert([
            ['id' => 3282, 'country_id' => 177, 'name' => 'Coimbra', 'country_code' => 'PT'],
        ]);

        DB::table('cities')->insert([
            ['id' => 91876, 'country_id' => 177, 'state_id' => 3282, 'name' => 'Cantanhede', 'country_code' => 'PT'],
        ]);
    }
}