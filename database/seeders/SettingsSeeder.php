<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\PaymentSettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::setValue(Setting::KEY_NEW_BANNER_DAYS, 7);
        app(PaymentSettingsService::class)->seedDefaultsFromConfig();
    }
}
