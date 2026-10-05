<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisor_import_batches', function (Blueprint $table) {
            $table->unsignedInteger('submitted_user_count')->default(0)->after('skipped_count');
            $table->string('stored_path')->nullable()->after('original_filename');
            $table->string('kind', 32)->default('import')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('advisor_import_batches', function (Blueprint $table) {
            $table->dropColumn(['submitted_user_count', 'stored_path', 'kind']);
        });
    }
};
