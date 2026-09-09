<?php

use App\Services\PaymentSettingsService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PaymentSettingsService::class)->seedDefaultsFromConfig();
    }

    public function down(): void
    {
        // Keep payment settings; they are managed from Power Admin.
    }
};
