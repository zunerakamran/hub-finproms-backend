<?php

use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short FK names — MySQL limits identifiers to 64 characters.
     *
     * @var array<string, string>
     */
    private const ON_BEHALF_FK = [
        'social_media_compliance_requests' => 'smc_req_on_behalf_by_fk',
        'social_media_compliance_request_versions' => 'smc_ver_on_behalf_by_fk',
        'general_compliance_requests' => 'gc_req_on_behalf_by_fk',
        'general_compliance_request_versions' => 'gc_ver_on_behalf_by_fk',
        'wc_change_requests' => 'wc_cr_on_behalf_by_fk',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'acting_advisor_id')) {
                $table->unsignedBigInteger('acting_advisor_id')->nullable()->after('acting_hub_id');
                $table->foreign('acting_advisor_id', 'users_acting_advisor_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        foreach (array_keys(self::ON_BEHALF_FK) as $table) {
            if ($table === 'wc_change_requests') {
                continue;
            }
            $this->addOnBehalfColumn($table);
        }

        if (Schema::hasTable('wc_change_requests')) {
            $this->addOnBehalfColumn('wc_change_requests', after: 'editor_id');
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

        $this->dropOnBehalfColumn('wc_change_requests');
        $this->dropOnBehalfColumn('general_compliance_request_versions');
        $this->dropOnBehalfColumn('general_compliance_requests');
        $this->dropOnBehalfColumn('social_media_compliance_request_versions');
        $this->dropOnBehalfColumn('social_media_compliance_requests');

        if (Schema::hasColumn('users', 'acting_advisor_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign('users_acting_advisor_fk');
                $table->dropColumn('acting_advisor_id');
            });
        }
    }

    private function addOnBehalfColumn(string $table, ?string $after = null): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'on_behalf_by_user_id')) {
            return;
        }

        $fk = self::ON_BEHALF_FK[$table] ?? ($table.'_ob_fk');

        Schema::table($table, function (Blueprint $blueprint) use ($after, $fk) {
            $col = $blueprint->unsignedBigInteger('on_behalf_by_user_id')->nullable();
            if ($after) {
                $col->after($after);
            }
            $blueprint->foreign('on_behalf_by_user_id', $fk)
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    private function dropOnBehalfColumn(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'on_behalf_by_user_id')) {
            return;
        }

        $fk = self::ON_BEHALF_FK[$table] ?? ($table.'_ob_fk');

        Schema::table($table, function (Blueprint $blueprint) use ($fk) {
            $blueprint->dropForeign($fk);
            $blueprint->dropColumn('on_behalf_by_user_id');
        });
    }
};
