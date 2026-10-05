<?php

use App\Services\PowerAdminCapabilitiesService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', PowerAdminCapabilitiesService::SETTING_KEY)->first();
        $map = [];
        if ($row && is_string($row->value) && $row->value !== '') {
            $decoded = json_decode($row->value, true);
            $map = is_array($decoded) ? $decoded : [];
        }

        if (! array_key_exists('pa_manage_hub_backups', $map)) {
            $map['pa_manage_hub_backups'] = true;
        }

        $now = now();
        if ($row) {
            DB::table('settings')->where('key', PowerAdminCapabilitiesService::SETTING_KEY)->update([
                'value' => json_encode($map),
                'updated_at' => $now,
            ]);
        } else {
            $defaults = [];
            foreach (PowerAdminCapabilitiesService::DEFINITIONS as $key => $meta) {
                $defaults[$key] = (bool) $meta['default'];
            }
            $defaults = array_merge($defaults, $map);
            DB::table('settings')->insert([
                'key' => PowerAdminCapabilitiesService::SETTING_KEY,
                'value' => json_encode($defaults),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', PowerAdminCapabilitiesService::SETTING_KEY)->first();
        if (! $row || ! is_string($row->value)) {
            return;
        }

        $map = json_decode($row->value, true);
        if (! is_array($map) || ! array_key_exists('pa_manage_hub_backups', $map)) {
            return;
        }

        unset($map['pa_manage_hub_backups']);
        DB::table('settings')->where('key', PowerAdminCapabilitiesService::SETTING_KEY)->update([
            'value' => json_encode($map),
            'updated_at' => now(),
        ]);
    }
};
