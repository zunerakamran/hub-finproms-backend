<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_KEY = 'dashboard_push_content';

    private const NEW_KEY = 'dashboard_control_white_label_hubs';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'acting_hub_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('acting_hub_id')
                    ->nullable()
                    ->after('stripe_payment_method_id')
                    ->constrained('hubs')
                    ->nullOnDelete();
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                if (array_key_exists(self::OLD_KEY, $caps)) {
                    $roleCaps[$role][self::NEW_KEY] = (bool) $caps[self::OLD_KEY];
                    unset($roleCaps[$role][self::OLD_KEY]);
                }
            }

            // Shared hub: ensure Power Admin + FinProms admin have the new control cap.
            if ($hub->isShared()) {
                foreach ([User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN] as $role) {
                    if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                        $roleCaps[$role] = [];
                    }
                    if (! array_key_exists(self::NEW_KEY, $roleCaps[$role])) {
                        $roleCaps[$role][self::NEW_KEY] = true;
                    }
                }
            }

            $checklist = $hub->resolvedChecklist();
            if (array_key_exists(self::OLD_KEY, $checklist)) {
                $checklist[self::NEW_KEY] = (bool) $checklist[self::OLD_KEY];
                unset($checklist[self::OLD_KEY]);
            }

            $any = false;
            foreach ($matrix->rolesForCapability(self::NEW_KEY) as $role) {
                if (! empty($roleCaps[$role][self::NEW_KEY])) {
                    $any = true;
                    break;
                }
            }
            $checklist[self::NEW_KEY] = $any;
            unset($checklist[self::OLD_KEY]);

            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                if (array_key_exists(self::NEW_KEY, $caps)) {
                    $roleCaps[$role][self::OLD_KEY] = (bool) $caps[self::NEW_KEY];
                    unset($roleCaps[$role][self::NEW_KEY]);
                }
            }

            $checklist = $hub->resolvedChecklist();
            if (array_key_exists(self::NEW_KEY, $checklist)) {
                $checklist[self::OLD_KEY] = (bool) $checklist[self::NEW_KEY];
                unset($checklist[self::NEW_KEY]);
            }

            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });

        if (Schema::hasColumn('users', 'acting_hub_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('acting_hub_id');
            });
        }
    }
};
