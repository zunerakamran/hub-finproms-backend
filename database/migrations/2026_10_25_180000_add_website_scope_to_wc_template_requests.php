<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * website_scope: network | firm | independent
 * Set from requester role (Network Admin / Firm Admin / advisor+others).
 * Display-only in UI — not editable after create.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        if (! Schema::hasColumn('wc_template_requests', 'website_scope')) {
            Schema::table('wc_template_requests', function (Blueprint $table) {
                $table->string('website_scope', 32)
                    ->default('independent')
                    ->after('request_type')
                    ->index();
            });
        }

        // Backfill from requester roles.
        $rows = DB::table('wc_template_requests')
            ->select(['id', 'requested_by_id'])
            ->whereNotNull('requested_by_id')
            ->get();

        foreach ($rows as $row) {
            $userId = (int) $row->requested_by_id;
            $roles = [];
            if (Schema::hasTable('user_roles')) {
                $roles = DB::table('user_roles')
                    ->where('user_id', $userId)
                    ->pluck('role')
                    ->map(fn ($r) => (string) $r)
                    ->all();
            }
            if ($roles === []) {
                $legacy = DB::table('users')->where('id', $userId)->value('role');
                if ($legacy) {
                    $roles = [(string) $legacy];
                }
            }

            $scope = 'independent';
            if (in_array(User::ROLE_NETWORK_ADMIN, $roles, true)) {
                $scope = 'network';
            } elseif (in_array(User::ROLE_FIRM_ADMIN, $roles, true)) {
                $scope = 'firm';
            }

            DB::table('wc_template_requests')
                ->where('id', $row->id)
                ->update(['website_scope' => $scope]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wc_template_requests') && Schema::hasColumn('wc_template_requests', 'website_scope')) {
            Schema::table('wc_template_requests', function (Blueprint $table) {
                $table->dropColumn('website_scope');
            });
        }
    }
};
