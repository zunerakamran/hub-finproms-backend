<?php

use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content hubs: remove local create/edit for posts & taxonomy (Central library owns create).
 * Keep view_* so admins can still list what exists on each hub.
 */
return new class extends Migration
{
    private const MANAGE_OFF = [
        'dashboard_manage_posts',
        'dashboard_manage_types',
        'dashboard_manage_categories',
        'dashboard_manage_tags',
    ];

    private const VIEW_ON = [
        'dashboard_view_posts',
        'dashboard_view_types',
        'dashboard_view_categories',
        'dashboard_view_tags',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $hubs = DB::table('hubs')
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->get();

        $hubAdminRoles = [
            User::ROLE_POWER_ADMIN,
            User::ROLE_FINPROMS_ADMIN,
            User::ROLE_CLIENT_ADMIN,
            User::ROLE_MANAGER,
        ];

        foreach ($hubs as $hub) {
            $stored = json_decode($hub->role_capabilities ?? 'null', true);
            if (! is_array($stored)) {
                $stored = [];
            }

            foreach (array_keys($stored) as $role) {
                if (! is_array($stored[$role])) {
                    continue;
                }
                foreach (self::MANAGE_OFF as $key) {
                    $stored[$role][$key] = false;
                }
            }

            foreach ($hubAdminRoles as $role) {
                if (! isset($stored[$role]) || ! is_array($stored[$role])) {
                    $stored[$role] = [];
                }
                foreach (self::VIEW_ON as $key) {
                    $stored[$role][$key] = true;
                }
                foreach (self::MANAGE_OFF as $key) {
                    $stored[$role][$key] = false;
                }
            }

            $checklist = json_decode($hub->checklist ?? 'null', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            foreach (self::MANAGE_OFF as $key) {
                $checklist[$key] = false;
            }
            foreach (self::VIEW_ON as $key) {
                $checklist[$key] = true;
            }

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
