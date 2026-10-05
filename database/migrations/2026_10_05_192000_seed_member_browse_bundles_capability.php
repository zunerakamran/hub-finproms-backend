<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed member_browse_bundles: ON for Shared / White-label roles, OFF on Central.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $roles = CapabilitiesMatrixService::MATRIX_ROLES;
        $hubs = DB::table('hubs')->orderBy('id')->get();

        foreach ($hubs as $hub) {
            $isCentral = ($hub->type ?? '') === Hub::TYPE_CENTRAL;
            $enabled = ! $isCentral;

            $roleCaps = json_decode($hub->role_capabilities ?? '{}', true);
            if (! is_array($roleCaps)) {
                $roleCaps = [];
            }

            foreach ($roles as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                $roleCaps[$role]['member_browse_bundles'] = $enabled;
            }

            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            $checklist['member_browse_bundles'] = $enabled;

            DB::table('hubs')->where('id', $hub->id)->update([
                'role_capabilities' => json_encode($roleCaps),
                'checklist' => json_encode($checklist),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive — leave seeded values in place.
    }
};
