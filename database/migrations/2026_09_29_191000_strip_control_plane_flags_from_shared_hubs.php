<?php

use App\Models\Hub;
use App\Services\ActingHubService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared hubs are content hubs (many allowed). Strip control-plane flags from
 * type=shared rows and ensure they can receive remote control from Central.
 * Does not change type=central rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $shared = DB::table('hubs')->where('type', Hub::TYPE_SHARED)->get();
        foreach ($shared as $hub) {
            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            $checklist['dashboard_control_white_label_hubs'] = false;
            $checklist['receive_content_from_shared'] = true;

            $roleCaps = json_decode($hub->role_capabilities ?? '{}', true);
            if (! is_array($roleCaps)) {
                $roleCaps = [];
            }
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                $caps[ActingHubService::CAPABILITY] = false;
                $roleCaps[$role] = $caps;
            }

            DB::table('hubs')->where('id', $hub->id)->update([
                'checklist' => json_encode($checklist),
                'role_capabilities' => $roleCaps === [] ? $hub->role_capabilities : json_encode($roleCaps),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup — no-op.
    }
};
