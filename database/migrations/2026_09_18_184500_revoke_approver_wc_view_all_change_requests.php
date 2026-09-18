<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Approvers must only see change requests they picked unless Power Admin
 * explicitly grants wc_view_all_change_requests. The WC seeder previously
 * enabled view-all for Approver by default, which leaked every approver's
 * history on /website-compliance/history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            if (! isset($roleCaps[User::ROLE_APPROVER]) || ! is_array($roleCaps[User::ROLE_APPROVER])) {
                $roleCaps[User::ROLE_APPROVER] = [];
            }

            if (empty($roleCaps[User::ROLE_APPROVER]['wc_view_all_change_requests'])) {
                return;
            }

            $roleCaps[User::ROLE_APPROVER]['wc_view_all_change_requests'] = false;

            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            $any = false;
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! empty($roleCaps[$role]['wc_view_all_change_requests'])) {
                    $any = true;
                    break;
                }
            }
            $checklist['wc_view_all_change_requests'] = $any;

            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Do not re-grant view-all to Approver — that was the bug.
    }
};
