<?php

use App\Models\Hub;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubRolesService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add website_admin system role and multi-assignee pivot for template deployments.
 *
 * Network / Firm Admins request sites and assign one or more Website Admins
 * (filtered by firm Delegated compliance access). Sections / cPanel still key
 * off assigned_advisor_id as the primary content owner (first selected admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_template_request_website_admins')) {
            Schema::create('wc_template_request_website_admins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('template_request_id')
                    ->constrained('wc_template_requests')
                    ->cascadeOnDelete();
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['template_request_id', 'user_id'], 'wc_tr_website_admins_unique');
            });
        }

        // Backfill pivot from legacy single assigned_advisor_id.
        if (Schema::hasTable('wc_template_requests') && Schema::hasTable('wc_template_request_website_admins')) {
            $rows = DB::table('wc_template_requests')
                ->whereNotNull('assigned_advisor_id')
                ->get(['id', 'assigned_advisor_id']);
            $now = now();
            foreach ($rows as $row) {
                $exists = DB::table('wc_template_request_website_admins')
                    ->where('template_request_id', $row->id)
                    ->where('user_id', $row->assigned_advisor_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('wc_template_request_website_admins')->insert([
                    'template_request_id' => $row->id,
                    'user_id' => $row->assigned_advisor_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $hubRoles = app(HubRolesService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $hubRoles) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            $defaults = $matrix->defaultRoleCapabilities($hub->type);
            $dirty = false;

            if (! array_key_exists(User::ROLE_WEBSITE_ADMIN, $stored)
                || ! is_array($stored[User::ROLE_WEBSITE_ADMIN])
                || $stored[User::ROLE_WEBSITE_ADMIN] === []) {
                $stored[User::ROLE_WEBSITE_ADMIN] = $defaults[User::ROLE_WEBSITE_ADMIN]
                    ?? ($defaults[User::ROLE_USER] ?? []);
                $dirty = true;
            }

            // Network / Firm Admin request + assign Website Admins (product default ON).
            foreach ([User::ROLE_NETWORK_ADMIN, User::ROLE_FIRM_ADMIN] as $role) {
                if (! isset($stored[$role]) || ! is_array($stored[$role])) {
                    $stored[$role] = $defaults[$role] ?? [];
                    $dirty = true;
                }
                foreach (['wc_request_deployments', 'wc_assign_website_templates'] as $cap) {
                    $desired = (bool) ($defaults[$role][$cap] ?? true);
                    if (($stored[$role][$cap] ?? null) !== $desired) {
                        $stored[$role][$cap] = $desired;
                        $dirty = true;
                    }
                }
            }

            if ($dirty) {
                $hub->forceFill(['role_capabilities' => $stored])->save();
            }

            try {
                $hubRoles->addRoleToHub($hub, User::ROLE_WEBSITE_ADMIN);
            } catch (\Throwable) {
                // Already present or not addable — ignore.
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wc_template_request_website_admins');

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            if (! array_key_exists(User::ROLE_WEBSITE_ADMIN, $stored)) {
                return;
            }
            unset($stored[User::ROLE_WEBSITE_ADMIN]);
            $hub->forceFill(['role_capabilities' => $stored])->save();
        });

        if (Schema::hasTable('user_roles')) {
            UserRole::query()->where('role', User::ROLE_WEBSITE_ADMIN)->delete();
        }
    }
};
