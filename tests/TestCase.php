<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->seedLookupTables();
    }

    protected function seedLookupTables(): void
    {
        dump('kyc_status before seed: ' . DB::table('kyc_status')->count());
        
        if (\DB::table('kyc_status')->count() === 0) {
            $this->seed(\Database\Seeders\KycStatusSeeder::class);
        }
        if (\DB::table('genders')->count() === 0) {
            $this->seed(\Database\Seeders\GenderSeeder::class);
        }
        if (\DB::table('countries')->count() === 0) {
            $this->seed(\Database\Seeders\TestWorldSeeder::class);
        }
        
        dump('kyc_status after seed: ' . DB::table('kyc_status')->count());
    }
}