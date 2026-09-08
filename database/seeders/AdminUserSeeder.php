<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@hubfinproms.com'],
            [
                'name' => 'Client Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_CLIENT_ADMIN,
                'credits' => 0,
            ]
        );
    }
}
