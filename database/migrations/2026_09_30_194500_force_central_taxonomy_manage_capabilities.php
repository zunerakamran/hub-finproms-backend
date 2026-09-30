<?php

use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure Central Hub Power Admin / FinProms can manage types, categories, tags
 * for the Central content library (same pattern as library capability force).
 */
return new class extends Migration
{
    private const KEYS = [
        'dashboard_manage_types',
        'dashboard_manage_categories',
        'dashboard_manage_tags',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $hubs = DB::table('hubs')->where('type', Hub::TYPE_CENTRAL)->get();

        foreach ($hubs as $hub) {
            $stored = json_decode($hub->role_capabilities ?? 'null', true);
            if (! is_array($stored)) {
                $stored = [];
            }

            foreach ([User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN] as $role) {
                if (! isset($stored[$role]) || ! is_array($stored[$role])) {
                    $stored[$role] = [];
                }
                foreach (self::KEYS as $key) {
                    $stored[$role][$key] = true;
                }
            }

            $checklist = json_decode($hub->checklist ?? 'null', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            foreach (self::KEYS as $key) {
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
