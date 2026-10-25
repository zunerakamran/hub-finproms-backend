<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-hub module invoice / payment settings (who pays: network / firm / user)
 * plus optional network-reseller branding on invoices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_module_invoice_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->constrained()->cascadeOnDelete()->unique();
            // Mutual-exclusion rules enforced in service (see ModuleInvoiceSettingsService).
            $table->boolean('network_pays_all')->default(true);
            $table->boolean('firms_pay_own')->default(false);
            $table->boolean('users_pay_own')->default(false);
            // Network pays the platform for everything, then may re-charge users
            // with its own pricing / branded invoice.
            $table->boolean('network_resells')->default(false);
            $table->string('reseller_logo_path')->nullable();
            $table->string('reseller_logo_name')->nullable();
            $table->text('reseller_invoice_text')->nullable();
            $table->string('reseller_invoice_format', 32)->default('standard');
            $table->timestamps();
        });

        Schema::table('hub_module_billings', function (Blueprint $table) {
            if (! Schema::hasColumn('hub_module_billings', 'payer_scope')) {
                $table->string('payer_scope', 16)->default('network')->after('billed_user_id');
            }
            if (! Schema::hasColumn('hub_module_billings', 'firm_id')) {
                $table->unsignedBigInteger('firm_id')->nullable()->after('payer_scope');
                $table->index(['hub_id', 'firm_id']);
                $table->index(['hub_id', 'payer_scope']);
            }
        });

        if (Schema::hasTable('hub_module_recurring_billings')) {
            Schema::table('hub_module_recurring_billings', function (Blueprint $table) {
                if (! Schema::hasColumn('hub_module_recurring_billings', 'payer_scope')) {
                    $table->string('payer_scope', 16)->default('network')->after('billed_user_id');
                }
                if (! Schema::hasColumn('hub_module_recurring_billings', 'firm_id')) {
                    $table->unsignedBigInteger('firm_id')->nullable()->after('payer_scope');
                    $table->index(['hub_id', 'firm_id']);
                    $table->index(['hub_id', 'payer_scope']);
                }
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_manage_module_invoice_settings';

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }
                if (! array_key_exists($key, $roleCaps[$role])) {
                    $roleCaps[$role][$key] = $role === User::ROLE_POWER_ADMIN
                        || (
                            $hub->isWhiteLabel()
                            && in_array($role, [
                                User::ROLE_CLIENT_ADMIN,
                                User::ROLE_NETWORK_ADMIN,
                                User::ROLE_FINPROMS_ADMIN,
                                'admin',
                            ], true)
                        );
                }
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
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset($roleCaps[$role]['dashboard_manage_module_invoice_settings']);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['dashboard_manage_module_invoice_settings']);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });

        if (Schema::hasTable('hub_module_recurring_billings')) {
            Schema::table('hub_module_recurring_billings', function (Blueprint $table) {
                if (Schema::hasColumn('hub_module_recurring_billings', 'firm_id')) {
                    $table->dropIndex(['hub_id', 'firm_id']);
                    $table->dropColumn('firm_id');
                }
                if (Schema::hasColumn('hub_module_recurring_billings', 'payer_scope')) {
                    $table->dropIndex(['hub_id', 'payer_scope']);
                    $table->dropColumn('payer_scope');
                }
            });
        }

        Schema::table('hub_module_billings', function (Blueprint $table) {
            if (Schema::hasColumn('hub_module_billings', 'firm_id')) {
                $table->dropIndex(['hub_id', 'firm_id']);
                $table->dropColumn('firm_id');
            }
            if (Schema::hasColumn('hub_module_billings', 'payer_scope')) {
                $table->dropIndex(['hub_id', 'payer_scope']);
                $table->dropColumn('payer_scope');
            }
        });

        Schema::dropIfExists('hub_module_invoice_settings');
    }
};
