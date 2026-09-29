<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $checklist = $hub->resolvedChecklist();

            // Migrate legacy compliance_check functionality → Social Media Compliance module.
            if (array_key_exists('compliance_check', is_array($hub->checklist) ? $hub->checklist : [])) {
                $legacy = filter_var($hub->checklist['compliance_check'], FILTER_VALIDATE_BOOLEAN);
                $checklist['module_social_media_compliance'] = $legacy
                    || (bool) ($checklist['module_social_media_compliance'] ?? false);
            }

            unset($checklist['compliance_check']);

            foreach (Hub::MODULE_KEYS as $moduleKey) {
                if (! array_key_exists($moduleKey, $checklist)) {
                    $checklist[$moduleKey] = false;
                }
            }

            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = false;
                    }
                }
            }

            // Keep legacy checklist OR sync for the new capability keys.
            foreach (Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS as $key) {
                $any = false;
                foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                    if (! empty($roleCaps[$role][$key])) {
                        $any = true;
                        break;
                    }
                }
                $checklist[$key] = $any;
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            if (array_key_exists('module_social_media_compliance', $checklist)
                && ! array_key_exists('compliance_check', $checklist)
            ) {
                $checklist['compliance_check'] = (bool) $checklist['module_social_media_compliance'];
            }

            foreach (array_merge(Hub::MODULE_KEYS, Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS) as $key) {
                unset($checklist[$key]);
            }

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach (Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }
};
