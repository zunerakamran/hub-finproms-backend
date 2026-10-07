<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('general_compliance_request_versions')) {
            return;
        }

        if (! Schema::hasColumn('general_compliance_request_versions', 'future_feedback')) {
            Schema::table('general_compliance_request_versions', function (Blueprint $table) {
                $table->text('future_feedback')->nullable()->after('feedback');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('general_compliance_request_versions')) {
            return;
        }

        if (Schema::hasColumn('general_compliance_request_versions', 'future_feedback')) {
            Schema::table('general_compliance_request_versions', function (Blueprint $table) {
                $table->dropColumn('future_feedback');
            });
        }
    }
};
