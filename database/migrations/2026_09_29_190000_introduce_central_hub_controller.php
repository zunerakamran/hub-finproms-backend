<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Introduce Central Hub Controller (type=central) as the control plane.
 *
 * - Ensures hubs.type can store central | shared | white_label (already a string).
 * - When HUB_SLUG=central (or HUB_PROMOTE_TO_CENTRAL=true), promote this deploy's
 *   hub row to type=central so Power Admin / registry live on Central.
 * - Enables receive_content_from_shared on Shared content hubs so Central can
 *   control them remotely once remote DB credentials are wired.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $slug = (string) config('hub.current_slug', 'shared');
        $promote = filter_var(env('HUB_PROMOTE_TO_CENTRAL', false), FILTER_VALIDATE_BOOLEAN)
            || $slug === 'central';

        if ($promote) {
            $targetSlug = $slug === 'central' ? 'central' : $slug;

            $existing = DB::table('hubs')->where('slug', $targetSlug)->first();
            if ($existing) {
                DB::table('hubs')->where('id', $existing->id)->update([
                    'type' => Hub::TYPE_CENTRAL,
                    'name' => $existing->name === 'Shared Hub' || $existing->name === ''
                        ? 'Central Hub Controller'
                        : $existing->name,
                    'updated_at' => now(),
                ]);
            } else {
                // Also handle legacy slug=shared → rename to central when promoting.
                if ($slug === 'central') {
                    $legacy = DB::table('hubs')->where('slug', 'shared')->where('type', 'shared')->first();
                    if ($legacy && filter_var(env('HUB_PROMOTE_TO_CENTRAL', false), FILTER_VALIDATE_BOOLEAN)) {
                        DB::table('hubs')->where('id', $legacy->id)->update([
                            'slug' => 'central',
                            'type' => Hub::TYPE_CENTRAL,
                            'name' => 'Central Hub Controller',
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }

        // Shared content hubs should accept remote content/control from Central by default.
        $sharedHubs = DB::table('hubs')->where('type', Hub::TYPE_SHARED)->get();
        foreach ($sharedHubs as $hub) {
            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            if (! array_key_exists('receive_content_from_shared', $checklist)
                || $checklist['receive_content_from_shared'] === false
            ) {
                // Only force on when not explicitly stored as false on an already-migrated row
                // that was never meant to receive — for brand-new shared content hubs, default on.
                if (! array_key_exists('receive_content_from_shared', $checklist)) {
                    $checklist['receive_content_from_shared'] = true;
                    DB::table('hubs')->where('id', $hub->id)->update([
                        'checklist' => json_encode($checklist),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        DB::table('hubs')
            ->where('type', Hub::TYPE_CENTRAL)
            ->where('slug', 'central')
            ->update([
                'type' => Hub::TYPE_SHARED,
                'name' => 'Shared Hub',
                'updated_at' => now(),
            ]);
    }
};
