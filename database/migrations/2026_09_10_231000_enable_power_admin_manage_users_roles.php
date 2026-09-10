<?php

use App\Models\Setting;
use App\Services\PowerAdminCapabilitiesService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $key = PowerAdminCapabilitiesService::SETTING_KEY;
        $raw = Setting::getValue($key, null);
        $map = [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }

        foreach (PowerAdminCapabilitiesService::DEFINITIONS as $flag => $meta) {
            if (! array_key_exists($flag, $map)) {
                $map[$flag] = (bool) $meta['default'];
            }
        }

        $map['pa_manage_users_roles'] = true;
        $map['pa_manage_power_capabilities'] = true;

        Setting::setValue($key, json_encode($map));
    }

    public function down(): void
    {
        $key = PowerAdminCapabilitiesService::SETTING_KEY;
        $raw = Setting::getValue($key, null);
        if (! is_string($raw) || $raw === '') {
            return;
        }

        $map = json_decode($raw, true);
        if (! is_array($map)) {
            return;
        }

        $map['pa_manage_users_roles'] = false;
        Setting::setValue($key, json_encode($map));
    }
};
