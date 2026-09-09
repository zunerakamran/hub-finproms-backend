<?php

namespace Database\Seeders;

use App\Services\AdvisorPricingService;
use Illuminate\Database\Seeder;

class AdvisorPricingSeeder extends Seeder
{
    public function run(): void
    {
        app(AdvisorPricingService::class)->seedDefaultsIfEmpty();
    }
}
