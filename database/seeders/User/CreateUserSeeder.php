<?php

namespace Database\Seeders\User;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CreateUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a default user
        $user = new \App\Models\User();
        $user->name = 'Test User';
        $user->email = 'user@gmail.com';
        $user->password = bcrypt('password');
        $user->save();


    }
}
