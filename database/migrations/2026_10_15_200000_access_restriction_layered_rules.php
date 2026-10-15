<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access restrictions become layered toggles (all can be on together)
 * instead of a single mutually exclusive mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('security_monitoring_settings')) {
            return;
        }

        Schema::table('security_monitoring_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('security_monitoring_settings', 'access_uk_only')) {
                $table->boolean('access_uk_only')->default(false)->after('failed_login_window_minutes');
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_uk_cities_enabled')) {
                $table->boolean('access_uk_cities_enabled')->default(false);
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_restrict_countries_enabled')) {
                $table->boolean('access_restrict_countries_enabled')->default(false);
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_allow_countries_enabled')) {
                $table->boolean('access_allow_countries_enabled')->default(false);
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_restricted_countries')) {
                $table->json('access_restricted_countries')->nullable();
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_allowed_countries')) {
                $table->json('access_allowed_countries')->nullable();
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_restrict_ips_enabled')) {
                $table->boolean('access_restrict_ips_enabled')->default(false);
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_allow_ips_enabled')) {
                $table->boolean('access_allow_ips_enabled')->default(false);
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_restricted_ips')) {
                $table->json('access_restricted_ips')->nullable();
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_allowed_ips')) {
                $table->json('access_allowed_ips')->nullable();
            }
        });

        // Migrate legacy single-mode rows into layered flags (best effort).
        if (Schema::hasColumn('security_monitoring_settings', 'access_restriction_mode')) {
            $rows = DB::table('security_monitoring_settings')->orderBy('id')->get();
            foreach ($rows as $row) {
                $mode = (string) ($row->access_restriction_mode ?? 'off');
                if ($mode === '' || $mode === 'off') {
                    continue;
                }

                $countries = $this->decodeJson($row->access_restriction_countries ?? null);
                $ips = $this->decodeJson($row->access_restriction_ips ?? null);

                $update = [
                    'access_uk_only' => false,
                    'access_uk_cities_enabled' => false,
                    'access_restrict_countries_enabled' => false,
                    'access_allow_countries_enabled' => false,
                    'access_restrict_ips_enabled' => false,
                    'access_allow_ips_enabled' => false,
                    'access_restricted_countries' => json_encode([]),
                    'access_allowed_countries' => json_encode([]),
                    'access_restricted_ips' => json_encode([]),
                    'access_allowed_ips' => json_encode([]),
                ];

                if ($mode === 'uk_only') {
                    $update['access_uk_only'] = true;
                } elseif ($mode === 'uk_cities') {
                    $update['access_uk_cities_enabled'] = true;
                } elseif ($mode === 'restrict_countries') {
                    $update['access_restrict_countries_enabled'] = true;
                    $update['access_restricted_countries'] = json_encode($countries);
                } elseif ($mode === 'allow_countries') {
                    $update['access_allow_countries_enabled'] = true;
                    $update['access_allowed_countries'] = json_encode($countries);
                } elseif ($mode === 'restrict_ips') {
                    $update['access_restrict_ips_enabled'] = true;
                    $update['access_restricted_ips'] = json_encode($ips);
                } elseif ($mode === 'allow_ips') {
                    $update['access_allow_ips_enabled'] = true;
                    $update['access_allowed_ips'] = json_encode($ips);
                }

                DB::table('security_monitoring_settings')
                    ->where('id', $row->id)
                    ->update($update);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('security_monitoring_settings')) {
            return;
        }

        Schema::table('security_monitoring_settings', function (Blueprint $table) {
            foreach ([
                'access_uk_only',
                'access_uk_cities_enabled',
                'access_restrict_countries_enabled',
                'access_allow_countries_enabled',
                'access_restricted_countries',
                'access_allowed_countries',
                'access_restrict_ips_enabled',
                'access_allow_ips_enabled',
                'access_restricted_ips',
                'access_allowed_ips',
            ] as $column) {
                if (Schema::hasColumn('security_monitoring_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * @return list<mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
