<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'gdpr_erased_at')) {
                $after = Schema::hasColumn('users', 'privacy_accepted_version')
                    ? 'privacy_accepted_version'
                    : (Schema::hasColumn('users', 'terms_accepted_version')
                        ? 'terms_accepted_version'
                        : 'email_verified_at');
                $table->timestamp('gdpr_erased_at')->nullable()->after($after);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'gdpr_erased_at')) {
                $table->dropColumn('gdpr_erased_at');
            }
        });
    }
};
