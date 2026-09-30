<?php

use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Re-assert Central content library for Power Admin / FinProms on Central hubs.
 * Earlier matrix saves could clobber the plane-only cell when applied in the wrong order.
 */
return new class extends Migration
{
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
                $stored[$role]['dashboard_central_content_library'] = true;
            }

            $checklist = json_decode($hub->checklist ?? 'null', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            $checklist['dashboard_central_content_library'] = true;

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
