<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Heal Central Hub Controller registry row after a fresh Central deploy.
 *
 * Cases covered:
 * - HUB_SLUG=central (or HUB_TYPE=central) but hubs.type was never promoted
 * - Only a legacy slug=shared row exists when this deploy is Central
 * - Central row exists but still has Shared-style packaging modules on
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $slug = (string) config('hub.current_slug', 'shared');
        $configuredType = strtolower(trim((string) config('hub.type', '')));
        $isCentralDeploy = $slug === 'central'
            || $configuredType === Hub::TYPE_CENTRAL
            || filter_var(env('HUB_PROMOTE_TO_CENTRAL', false), FILTER_VALIDATE_BOOLEAN);

        if (! $isCentralDeploy) {
            return;
        }

        $targetSlug = $slug === 'central' || $configuredType === Hub::TYPE_CENTRAL
            ? ($slug === 'central' ? 'central' : $slug)
            : $slug;

        if ($configuredType === Hub::TYPE_CENTRAL && $slug !== '') {
            $targetSlug = $slug;
        }
        if ($slug === 'central') {
            $targetSlug = 'central';
        }

        $existing = DB::table('hubs')->where('slug', $targetSlug)->first();

        if (! $existing && $targetSlug === 'central') {
            $legacy = DB::table('hubs')->where('slug', 'shared')->where('type', Hub::TYPE_SHARED)->first();
            if ($legacy) {
                DB::table('hubs')->where('id', $legacy->id)->update([
                    'slug' => 'central',
                    'type' => Hub::TYPE_CENTRAL,
                    'name' => 'Central Hub Controller',
                    'updated_at' => now(),
                ]);
                $existing = DB::table('hubs')->where('slug', 'central')->first();
            }
        }

        if (! $existing) {
            return;
        }

        $checklist = json_decode($existing->checklist ?? '{}', true);
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
        $checklist['member_view_site_pages'] = false;
        $checklist['one_off_purchase'] = false;
        $checklist['public_subscribe'] = false;
        $checklist['receive_content_from_shared'] = false;
        $checklist['dashboard_control_white_label_hubs'] = true;
        $checklist['charge_amount_per_module'] = false;

        DB::table('hubs')->where('id', $existing->id)->update([
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'name' => ($existing->name === 'Shared Hub' || $existing->name === '')
                ? 'Central Hub Controller'
                : $existing->name,
            'checklist' => json_encode($checklist),
            'role_capabilities' => json_encode(
                app(\App\Services\CapabilitiesMatrixService::class)
                    ->defaultRoleCapabilities(Hub::TYPE_CENTRAL)
            ),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Irreversible heal — no-op.
    }
};
