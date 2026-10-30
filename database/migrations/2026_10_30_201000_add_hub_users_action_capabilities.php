<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Per-action Hub → Users capabilities (icons gated individually).
     *
     * @var list<string>
     */
    private const KEYS = [
        'hub_users_change_password',
        'hub_users_edit_roles',
        'hub_users_edit_modules',
        'hub_users_change_email',
        'hub_users_toggle_otp',
        'hub_users_toggle_authenticator',
        'hub_users_edit_training',
    ];

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);
            $checklist = $hub->resolvedChecklist();

            foreach (self::KEYS as $key) {
                foreach ($roleCaps as $role => $caps) {
                    if (! is_array($caps)) {
                        $roleCaps[$role] = [];
                    }
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = (bool) ($defaults[$role][$key] ?? false);
                    }
                }

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
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach (self::KEYS as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            foreach (self::KEYS as $key) {
                unset($checklist[$key]);
            }
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }
};
