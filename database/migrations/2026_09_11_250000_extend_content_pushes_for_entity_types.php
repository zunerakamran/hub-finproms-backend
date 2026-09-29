<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_pushes', function (Blueprint $table) {
            $table->string('entity_type', 32)->default('post')->after('post_id');
            $table->unsignedBigInteger('entity_id')->nullable()->after('entity_type');
            $table->string('entity_label')->nullable()->after('entity_id');
        });

        // Allow taxonomy / bundle pushes without a local post_id.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE content_pushes MODIFY post_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE content_pushes ALTER COLUMN post_id DROP NOT NULL');
        }

        DB::table('content_pushes')->whereNull('entity_id')->update([
            'entity_type' => 'post',
        ]);
        DB::table('content_pushes')->whereNull('entity_id')->whereNotNull('post_id')->update([
            'entity_id' => DB::raw('post_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('content_pushes', function (Blueprint $table) {
            $table->dropColumn(['entity_type', 'entity_id', 'entity_label']);
        });
    }
};
