<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed gc_download_attachments: in-hub preview is always available to viewers;
 * this capability controls whether the Save / Download control is shown and allowed.
 *
 * Existing hubs that already granted view/submit/review keep download enabled so
 * behaviour stays familiar (preview-first, download still available as a button).
 */
return new class extends Migration
{
    private const KEY = 'gc_download_attachments';

    /** @var list<string> */
    private const VIEW_KEYS = [
        'gc_submit_request',
        'gc_view_own_requests',
        'gc_view_all_requests',
        'gc_review_requests',
        'gc_assign_requests',
        'gc_change_request_status',
    ];

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $key = self::KEY;

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    $roleCaps[$role] = [];
                    $caps = [];
                }
                if (array_key_exists($key, $roleCaps[$role])) {
                    continue;
                }

                $hadViewAccess = false;
                foreach (self::VIEW_KEYS as $viewKey) {
                    if (! empty($caps[$viewKey])) {
                        $hadViewAccess = true;
                        break;
                    }
                }

                $roleCaps[$role][$key] = $hadViewAccess
                    || (bool) ($defaults[$role][$key] ?? false);
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            $any = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[$key])) {
                    $any = true;
                    break;
                }
            }
            $checklist[$key] = $any;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        $key = self::KEY;

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($key) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset($roleCaps[$role][$key]);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist[$key]);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }
};
