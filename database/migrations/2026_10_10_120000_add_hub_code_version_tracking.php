<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 64)->unique();
            $table->string('backend_version', 64)->nullable();
            $table->string('frontend_version', 64)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_latest')->default(false)->index();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'code_version')) {
                $table->string('code_version', 64)->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_backend_version')) {
                $table->string('code_backend_version', 64)->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_frontend_version')) {
                $table->string('code_frontend_version', 64)->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_version_status')) {
                $table->string('code_version_status', 32)->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_version_source')) {
                $table->string('code_version_source', 32)->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_version_checked_at')) {
                $table->timestamp('code_version_checked_at')->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_version_check_error')) {
                $table->text('code_version_check_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $columns = [
                'code_version',
                'code_backend_version',
                'code_frontend_version',
                'code_version_status',
                'code_version_source',
                'code_version_checked_at',
                'code_version_check_error',
            ];
            $drop = array_values(array_filter($columns, fn (string $col) => Schema::hasColumn('hubs', $col)));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::dropIfExists('hub_releases');
    }
};
