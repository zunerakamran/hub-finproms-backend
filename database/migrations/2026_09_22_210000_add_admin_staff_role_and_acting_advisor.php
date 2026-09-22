<?php

use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'acting_advisor_id')) {
                $table->foreignId('acting_advisor_id')
                    ->nullable()
                    ->after('acting_hub_id')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        $this->addOnBehalfColumn('social_media_compliance_requests');
        $this->addOnBehalfColumn('social_media_compliance_request_versions');
        $this->addOnBehalfColumn('general_compliance_requests');
        $this->addOnBehalfColumn('general_compliance_request_versions');

        if (Schema::hasTable('wc_change_requests') && ! Schema::hasColumn('wc_change_requests', 'on_behalf_by_user_id')) {
            Schema::table('wc_change_requests', function (Blueprint $table) {
                $table->foreignId('on_behalf_by_user_id')
                    ->nullable()
                    ->after('editor_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        // Seed admin_staff matrix column from advisor defaults (or empty) for existing hubs.
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            if (array_key_exists(User::ROLE_ADMIN_STAFF, $stored)) {
                return;
            }

            $stored[User::ROLE_ADMIN_STAFF] = is_array($stored[User::ROLE_ADVISOR] ?? null)
                ? $stored[User::ROLE_ADVISOR]
                : [];
            $hub->forceFill(['role_capabilities' => $stored])->save();
        });
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            if (! array_key_exists(User::ROLE_ADMIN_STAFF, $stored)) {
                return;
            }
            unset($stored[User::ROLE_ADMIN_STAFF]);
            $hub->forceFill(['role_capabilities' => $stored])->save();
        });

        if (Schema::hasTable('wc_change_requests') && Schema::hasColumn('wc_change_requests', 'on_behalf_by_user_id')) {
            Schema::table('wc_change_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('on_behalf_by_user_id');
            });
        }

        $this->dropOnBehalfColumn('general_compliance_request_versions');
        $this->dropOnBehalfColumn('general_compliance_requests');
        $this->dropOnBehalfColumn('social_media_compliance_request_versions');
        $this->dropOnBehalfColumn('social_media_compliance_requests');

        if (Schema::hasColumn('users', 'acting_advisor_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('acting_advisor_id');
            });
        }
    }

    private function addOnBehalfColumn(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'on_behalf_by_user_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->foreignId('on_behalf_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    private function dropOnBehalfColumn(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'on_behalf_by_user_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropConstrainedForeignId('on_behalf_by_user_id');
        });
    }
};
