<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('general_compliance_request_attachments')) {
            return;
        }

        if (! Schema::hasColumn('general_compliance_request_attachments', 'is_encrypted')) {
            Schema::table('general_compliance_request_attachments', function (Blueprint $table) {
                $table->boolean('is_encrypted')->default(false)->after('size_bytes');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('general_compliance_request_attachments')) {
            return;
        }

        if (Schema::hasColumn('general_compliance_request_attachments', 'is_encrypted')) {
            Schema::table('general_compliance_request_attachments', function (Blueprint $table) {
                $table->dropColumn('is_encrypted');
            });
        }
    }
};
