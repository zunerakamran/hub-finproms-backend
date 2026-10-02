<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attribution for supporting files shown in version history (SMC / GC / WC).
     * source: submit|resubmit|confirm_feedback|review|change_status|approve|reject|approve_with_feedback|schedule
     */
    public function up(): void
    {
        $this->addAttributionColumns('social_media_compliance_request_attachments');
        $this->addAttributionColumns('general_compliance_request_attachments');
        $this->addAttributionColumns('wc_change_request_attachments');
    }

    public function down(): void
    {
        $this->dropAttributionColumns('social_media_compliance_request_attachments');
        $this->dropAttributionColumns('general_compliance_request_attachments');
        $this->dropAttributionColumns('wc_change_request_attachments');
    }

    private function addAttributionColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'uploaded_by_user_id')) {
                $blueprint->unsignedBigInteger('uploaded_by_user_id')->nullable()->after('sort_order');
            }
            if (! Schema::hasColumn($table, 'uploaded_by_name')) {
                $blueprint->string('uploaded_by_name')->nullable()->after('uploaded_by_user_id');
            }
            if (! Schema::hasColumn($table, 'source')) {
                $blueprint->string('source', 40)->nullable()->after('uploaded_by_name');
            }
        });
    }

    private function dropAttributionColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            foreach (['source', 'uploaded_by_name', 'uploaded_by_user_id'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $blueprint->dropColumn($column);
                }
            }
        });
    }
};
