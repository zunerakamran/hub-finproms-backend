<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Shared hub FinProms admin
        User::updateOrCreate(
            ['email' => 'finproms@hubfinproms.com'],
            [
                'name' => 'FinProms Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_FINPROMS_ADMIN,
                'credits' => 0,
            ]
        );

        // White-label style client admin (same DB for local testing)
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
