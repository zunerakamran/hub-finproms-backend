<?php

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubRolesService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Add network_admin / firm_admin system roles (§2.7 / §3.4):
 * - Seed matrix columns from defaults on every hub registry row
 * - Backfill roles for existing firms.head_user_id appointments
 */
return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $hubRoles = app(HubRolesService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $hubRoles) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            $defaults = $matrix->defaultRoleCapabilities($hub->type);
            $dirty = false;

            foreach ([User::ROLE_NETWORK_ADMIN, User::ROLE_FIRM_ADMIN] as $role) {
                if (array_key_exists($role, $stored) && is_array($stored[$role]) && $stored[$role] !== []) {
                    continue;
                }
                $stored[$role] = $defaults[$role] ?? ($defaults[User::ROLE_USER] ?? []);
                $dirty = true;
            }

            if ($dirty) {
                $hub->forceFill(['role_capabilities' => $stored])->save();
            }

            // Make columns visible on Manage roles / matrix without waiting for a user.
            foreach ([User::ROLE_NETWORK_ADMIN, User::ROLE_FIRM_ADMIN] as $role) {
                try {
                    $hubRoles->addRoleToHub($hub, $role);
                } catch (\Throwable) {
                    // Already present or not addable on this hub type — ignore.
                }
            }
        });

        $this->backfillHeadsOnConnection(null);

        // White-label remote DBs: head backfill runs when those hubs are next
        // reached; registry matrix seed above covers capability columns.
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            $dirty = false;
            foreach ([User::ROLE_NETWORK_ADMIN, User::ROLE_FIRM_ADMIN] as $role) {
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

        if (Schema::hasTable('user_roles')) {
            UserRole::query()
                ->whereIn('role', [User::ROLE_NETWORK_ADMIN, User::ROLE_FIRM_ADMIN])
                ->delete();
        }
    }

    private function backfillHeadsOnConnection(?string $connection): void
    {
        if ($connection
            ? ! Schema::connection($connection)->hasTable('firms')
            : ! Schema::hasTable('firms')
        ) {
            return;
        }

        if ($connection
            ? ! Schema::connection($connection)->hasColumn('firms', 'head_user_id')
            : ! Schema::hasColumn('firms', 'head_user_id')
        ) {
            return;
        }

        $firmQuery = $connection ? Firm::on($connection) : new Firm;
        $firms = $firmQuery->newQuery()->whereNotNull('head_user_id')->get();

        foreach ($firms as $firm) {
            $userQuery = $connection ? User::on($connection) : new User;
            $head = $userQuery->newQuery()->find($firm->head_user_id);
            if (! $head) {
                continue;
            }

            $role = $firm->isCentral() ? User::ROLE_NETWORK_ADMIN : User::ROLE_FIRM_ADMIN;
            if ($head->hasRole($role)) {
                continue;
            }

            $keys = $head->roleKeys();
            $other = $role === User::ROLE_NETWORK_ADMIN
                ? User::ROLE_FIRM_ADMIN
                : User::ROLE_NETWORK_ADMIN;
            $keys = array_values(array_filter($keys, fn (string $key) => $key !== $other));
            if (! in_array($role, $keys, true)) {
                $keys[] = $role;
            }

            try {
                app(\App\Services\UserRolesService::class)->syncRoles(
                    $head,
                    $keys,
                    log: false,
                    syncFirmHead: false,
                );
            } catch (\Throwable) {
                // Skip unresolvable rows
            }
        }
    }
};
