<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add member_view_site_pages capability.
 * On Central Hub Controller: OFF for every role (no member website).
 * On Shared / White-label: ON for every role by default (unless already stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $roles = CapabilitiesMatrixService::MATRIX_ROLES;
        $hubs = DB::table('hubs')->get();

        foreach ($hubs as $hub) {
            $roleCaps = json_decode($hub->role_capabilities ?? '{}', true);
            if (! is_array($roleCaps)) {
                $roleCaps = [];
            }

            $isCentral = $hub->type === Hub::TYPE_CENTRAL;
            $defaultOn = ! $isCentral;

            foreach ($roles as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                // Always force OFF on Central; only set when missing on content hubs.
                if ($isCentral || ! array_key_exists('member_view_site_pages', $roleCaps[$role])) {
                    $roleCaps[$role]['member_view_site_pages'] = $defaultOn;
                }
            }

            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            if ($isCentral) {
                $checklist['member_view_site_pages'] = false;
            } elseif (! array_key_exists('member_view_site_pages', $checklist)) {
                $checklist['member_view_site_pages'] = true;
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
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $hubs = DB::table('hubs')->get();
        foreach ($hubs as $hub) {
            $roleCaps = json_decode($hub->role_capabilities ?? '{}', true);
            if (is_array($roleCaps)) {
                foreach ($roleCaps as $role => $caps) {
                    if (is_array($caps)) {
                        unset($roleCaps[$role]['member_view_site_pages']);
                    }
                }
            }
            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (is_array($checklist)) {
                unset($checklist['member_view_site_pages']);
            }
            DB::table('hubs')->where('id', $hub->id)->update([
                'role_capabilities' => is_array($roleCaps) ? json_encode($roleCaps) : $hub->role_capabilities,
                'checklist' => is_array($checklist) ? json_encode($checklist) : $hub->checklist,
                'updated_at' => now(),
            ]);
        }
    }
};
