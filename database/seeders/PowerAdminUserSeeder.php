<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PowerAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'power@hubfinproms.com'],
            [
                'name' => 'Power Admin',
                'password' => Hash::make('password'),
                'role' => User::ROLE_POWER_ADMIN,
                'credits' => 0,
            ]
        );
    }
}
