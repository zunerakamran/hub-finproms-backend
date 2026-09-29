<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_requests') && ! Schema::hasTable('social_media_compliance_requests')) {
            Schema::rename('compliance_requests', 'social_media_compliance_requests');
        }

        if (Schema::hasTable('compliance_request_versions') && ! Schema::hasTable('social_media_compliance_request_versions')) {
            Schema::rename('compliance_request_versions', 'social_media_compliance_request_versions');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('social_media_compliance_request_versions') && ! Schema::hasTable('compliance_request_versions')) {
            Schema::rename('social_media_compliance_request_versions', 'compliance_request_versions');
        }

        if (Schema::hasTable('social_media_compliance_requests') && ! Schema::hasTable('compliance_requests')) {
            Schema::rename('social_media_compliance_requests', 'compliance_requests');
        }
    }
};
