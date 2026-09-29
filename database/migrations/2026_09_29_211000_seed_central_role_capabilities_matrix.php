<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure Central Hub Controller has a full role_capabilities matrix seeded
 * (Power Admin defaults visible). Partial member-only blobs from earlier
 * migrations left dashboard / switcher cells looking empty in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $centrals = DB::table('hubs')->where('type', Hub::TYPE_CENTRAL)->get();

        foreach ($centrals as $hub) {
            $stored = json_decode($hub->role_capabilities ?? 'null', true);
            $defaults = $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL);

            if (! is_array($stored) || $stored === []) {
                DB::table('hubs')->where('id', $hub->id)->update([
                    'role_capabilities' => json_encode($defaults),
                    'updated_at' => now(),
                ]);

                continue;
            }

            // Merge: keep explicit stored cells, fill missing keys from Central defaults.
            foreach ($defaults as $role => $caps) {
                if (! isset($stored[$role]) || ! is_array($stored[$role])) {
                    $stored[$role] = $caps;

                    continue;
                }
                foreach ($caps as $key => $default) {
                    if (! array_key_exists($key, $stored[$role])) {
                        $stored[$role][$key] = $default;
                    }
                }
            }

            DB::table('hubs')->where('id', $hub->id)->update([
                'role_capabilities' => json_encode($stored),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // no-op
    }
};
