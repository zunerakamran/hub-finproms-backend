<?php

use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Managers should have the same review actions as Approvers (approve / reject / AWF / pickup),
 * plus their existing assign / view-all tools.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keys = [
            'wc_review_change_requests',
            'wc_assign_change_requests',
            'wc_view_all_change_requests',
            'wc_change_request_status',
        ];

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($keys) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            if (! isset($roleCaps[User::ROLE_MANAGER]) || ! is_array($roleCaps[User::ROLE_MANAGER])) {
                $roleCaps[User::ROLE_MANAGER] = [];
            }

            $changed = false;
            foreach ($keys as $key) {
                if (empty($roleCaps[User::ROLE_MANAGER][$key])) {
                    $roleCaps[User::ROLE_MANAGER][$key] = true;
                    $changed = true;
                }
            }

            if (! $changed) {
                return;
            }

            $checklist = $hub->resolvedChecklist();
            foreach ($keys as $key) {
                $any = false;
                foreach ($roleCaps as $caps) {
                    if (! empty($caps[$key])) {
                        $any = true;
                        break;
                    }
                }
                $checklist[$key] = $any;
            }

            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Intentionally left blank — do not revoke manager review access on rollback.
    }
};
