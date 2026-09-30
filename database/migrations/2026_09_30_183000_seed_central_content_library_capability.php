<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enable Central content library for Power Admin / FinProms admin on Central hubs.
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
            if (! is_array($stored)) {
                $stored = [];
            }

            $defaults = $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL);
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

            foreach ([User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN] as $role) {
                if (! isset($stored[$role]) || ! is_array($stored[$role])) {
                    $stored[$role] = [];
                }
                $stored[$role]['dashboard_central_content_library'] = true;
                $stored[$role]['dashboard_manage_posts'] = false;
            }

            $checklist = json_decode($hub->checklist ?? 'null', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            $checklist['dashboard_central_content_library'] = true;
            $checklist['manual_posts'] = false;
            $checklist['ai_posts'] = false;

            DB::table('hubs')->where('id', $hub->id)->update([
                'role_capabilities' => json_encode($stored),
                'checklist' => json_encode($checklist),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // no-op
    }
};
