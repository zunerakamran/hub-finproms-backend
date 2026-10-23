<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubRolesService;
use Illuminate\Database\Migrations\Migration;

/**
 * Add top_management, auditor, accounts_admin system roles (§2.7):
 * seed matrix columns from defaults and add columns on every hub.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const ROLES = [
        User::ROLE_TOP_MANAGEMENT,
        User::ROLE_AUDITOR,
        User::ROLE_ACCOUNTS_ADMIN,
    ];

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $hubRoles = app(HubRolesService::class);
        $matrix->forgetResolvedCaches();

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $hubRoles) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            $defaults = $matrix->defaultRoleCapabilities($hub->type);
            $dirty = false;

            foreach (self::ROLES as $role) {
                if (array_key_exists($role, $stored) && is_array($stored[$role]) && $stored[$role] !== []) {
                    continue;
                }
                $stored[$role] = $defaults[$role] ?? ($defaults[User::ROLE_USER] ?? []);
                $dirty = true;
            }

            if ($dirty) {
                $hub->forceFill(['role_capabilities' => $stored])->save();
            }

            foreach (self::ROLES as $role) {
                try {
                    $hubRoles->addRoleToHub($hub, $role);
                } catch (\Throwable) {
                    // Already present or not addable — ignore.
                }
            }
        });

        $matrix->forgetResolvedCaches();
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            $dirty = false;
            foreach (self::ROLES as $role) {
                if (! array_key_exists($role, $stored)) {
                    continue;
                }
                unset($stored[$role]);
                $dirty = true;
            }
            if ($dirty) {
                $hub->forceFill(['role_capabilities' => $stored])->save();
            }
        });
    }
};
