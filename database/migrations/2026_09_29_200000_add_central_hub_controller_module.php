<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure Central Hub rows have the locked Central Hub Controller module on,
 * and content product modules off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $centrals = DB::table('hubs')->where('type', Hub::TYPE_CENTRAL)->get();
        foreach ($centrals as $hub) {
            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }

            $checklist['module_central_hub'] = true;
            $checklist['module_shared_hub'] = false;
            $checklist['module_white_label_hub'] = false;
            $checklist['module_social_media_template_library'] = false;
            $checklist['module_social_media_compliance'] = false;
            $checklist['module_website_template_library'] = false;
            $checklist['module_website_compliance'] = false;
            $checklist['module_general_compliance'] = false;
            $checklist['member_browse_catalog'] = false;
            $checklist['member_view_plans'] = false;
            $checklist['member_purchase_content'] = false;
            $checklist['member_download_content'] = false;
            $checklist['one_off_purchase'] = false;
            $checklist['public_subscribe'] = false;
            $checklist['receive_content_from_shared'] = false;
            $checklist['dashboard_control_white_label_hubs'] = true;

            DB::table('hubs')->where('id', $hub->id)->update([
                'checklist' => json_encode($checklist),
                'updated_at' => now(),
            ]);
        }

        // Content hubs must not claim the central packaging module.
        $contentHubs = DB::table('hubs')
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->get();
        foreach ($contentHubs as $hub) {
            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                continue;
            }
            if (! ($checklist['module_central_hub'] ?? false)) {
                continue;
            }
            $checklist['module_central_hub'] = false;
            DB::table('hubs')->where('id', $hub->id)->update([
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
