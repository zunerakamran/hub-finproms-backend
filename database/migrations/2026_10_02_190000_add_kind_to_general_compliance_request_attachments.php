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

        if (! Schema::hasColumn('general_compliance_request_attachments', 'kind')) {
            Schema::table('general_compliance_request_attachments', function (Blueprint $table) {
                // attachment = primary submission files; supporting_file = evidence / review extras
                $table->string('kind', 32)->default('attachment')->after('version_id');
                $table->index(['version_id', 'kind', 'sort_order'], 'gc_attachments_version_kind_sort_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('general_compliance_request_attachments')) {
            return;
        }

        if (Schema::hasColumn('general_compliance_request_attachments', 'kind')) {
            Schema::table('general_compliance_request_attachments', function (Blueprint $table) {
                $table->dropIndex('gc_attachments_version_kind_sort_idx');
                $table->dropColumn('kind');
            });
        }
    }
};
