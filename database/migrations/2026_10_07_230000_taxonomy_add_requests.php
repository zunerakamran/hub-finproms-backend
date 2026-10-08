<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_add_requests')) {
            Schema::create('taxonomy_add_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('target', 40);
                $table->string('proposed_name', 100);
                $table->text('remarks');
                $table->string('status', 20)->default('Pending');
                $table->text('review_note')->nullable();
                // No FK: reviewer may be a Central Power Admin while this table is on a remounted hub DB.
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedBigInteger('created_entity_id')->nullable();
                $table->timestamps();

                $table->index(['status', 'target']);
                $table->index(['user_id', 'status']);
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'taxonomy_request_add';

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    $roleCaps[$role] = [];
                }
                if (! array_key_exists($key, $roleCaps[$role])) {
                    $roleCaps[$role][$key] = (bool) ($defaults[$role][$key] ?? false);
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
                    unset($roleCaps[$role]['taxonomy_request_add']);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['taxonomy_request_add']);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });

        Schema::dropIfExists('taxonomy_add_requests');
    }
};
