<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Network vs firm payment cards + scoped module invoice views + reseller invoices.
 * Personal cards stay on the user (Profile) — no capability.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hub_payment_profiles')) {
            Schema::create('hub_payment_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hub_id')->constrained()->cascadeOnDelete();
                $table->string('scope', 16); // network | firm
                $table->foreignId('firm_id')->nullable()->constrained()->nullOnDelete();
                $table->string('stripe_customer_id')->nullable();
                $table->string('stripe_payment_method_id')->nullable();
                $table->foreignId('managed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['hub_id', 'scope']);
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        $map = [
            // old => [new caps to OR-enable for same roles that had old]
            'dashboard_manage_payment_card' => [
                'dashboard_manage_network_payment_card',
                'dashboard_manage_firm_payment_card',
            ],
            'dashboard_view_module_invoices' => [
                'dashboard_view_network_module_invoices',
                'dashboard_view_firm_module_invoices',
            ],
            'dashboard_view_all_module_invoices' => [
                'dashboard_view_network_module_invoices',
                'dashboard_view_firm_module_invoices',
            ],
        ];

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $map) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }

                foreach ($map as $old => $news) {
                    if (! empty($roleCaps[$role][$old])) {
                        foreach ($news as $new) {
                            $roleCaps[$role][$new] = true;
                        }
                    }
                }

                // Sensible defaults when nothing mapped yet.
                if ($role === User::ROLE_NETWORK_ADMIN || $role === User::ROLE_CLIENT_ADMIN || $role === 'admin') {
                    $roleCaps[$role]['dashboard_manage_network_payment_card'] = true;
                    $roleCaps[$role]['dashboard_view_network_module_invoices'] = true;
                }
                if ($role === User::ROLE_FIRM_ADMIN) {
                    $roleCaps[$role]['dashboard_manage_firm_payment_card'] = true;
                    $roleCaps[$role]['dashboard_view_firm_module_invoices'] = true;
                }
                if ($role === User::ROLE_ACCOUNTS_ADMIN) {
                    $roleCaps[$role]['dashboard_manage_network_payment_card'] = true;
                    $roleCaps[$role]['dashboard_manage_firm_payment_card'] = true;
                    $roleCaps[$role]['dashboard_view_network_module_invoices'] = true;
                    $roleCaps[$role]['dashboard_view_firm_module_invoices'] = true;
                }
                if (in_array($role, [User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN], true)) {
                    $roleCaps[$role]['dashboard_view_network_module_invoices'] = true;
                    $roleCaps[$role]['dashboard_view_firm_module_invoices'] = true;
                    $roleCaps[$role]['dashboard_view_reseller_invoices'] = true;
                }
                if ($role === User::ROLE_NETWORK_ADMIN || $role === User::ROLE_CLIENT_ADMIN) {
                    $roleCaps[$role]['dashboard_view_reseller_invoices'] = true;
                }
            }

            $hub->role_capabilities = $roleCaps;
            $checklist = $hub->resolvedChecklist();
            foreach ([
                'dashboard_manage_network_payment_card',
                'dashboard_manage_firm_payment_card',
                'dashboard_view_network_module_invoices',
                'dashboard_view_firm_module_invoices',
                'dashboard_view_reseller_invoices',
            ] as $key) {
                $any = false;
                foreach ($roleCaps as $caps) {
                    if (! empty($caps[$key])) {
                        $any = true;
                        break;
                    }
                }
                $checklist[$key] = $any;
            }
            $hub->checklist = $checklist;
            $hub->save();
        });

        $matrix->forgetResolvedCaches();
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_payment_profiles');
    }
};
