<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central Hub Controller has no member website / catalog.
 * Force member site-page + catalog caps OFF in checklist and every role column.
 */
return new class extends Migration
{
    private const MEMBER_OFF = [
        'member_view_site_pages',
        'member_browse_catalog',
        'member_view_plans',
        'member_purchase_content',
        'member_download_content',
        'member_in_app_edit',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $roles = CapabilitiesMatrixService::MATRIX_ROLES;
        $hubs = DB::table('hubs')->where('type', Hub::TYPE_CENTRAL)->get();

        foreach ($hubs as $hub) {
            $roleCaps = json_decode($hub->role_capabilities ?? '{}', true);
            if (! is_array($roleCaps)) {
                $roleCaps = [];
            }

            foreach ($roles as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (self::MEMBER_OFF as $key) {
                    $roleCaps[$role][$key] = false;
                }
            }

            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            foreach (self::MEMBER_OFF as $key) {
                $checklist[$key] = false;
            }

            DB::table('hubs')->where('id', $hub->id)->update([
                'role_capabilities' => json_encode($roleCaps),
                'checklist' => json_encode($checklist),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive — do not re-enable member website on Central.
    }
};
