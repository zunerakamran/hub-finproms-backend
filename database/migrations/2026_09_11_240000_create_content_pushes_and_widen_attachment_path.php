<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE posts MODIFY attachment_path VARCHAR(2048) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE posts ALTER COLUMN attachment_path TYPE VARCHAR(2048)');
        }

        Schema::create('content_pushes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_hub_id')->constrained('hubs')->cascadeOnDelete();
            $table->foreignId('target_hub_id')->constrained('hubs')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('pushed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('remote_post_id')->nullable();
            $table->string('status', 32)->default('success');
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['target_hub_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pushes');

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE posts MODIFY attachment_path VARCHAR(255) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE posts ALTER COLUMN attachment_path TYPE VARCHAR(255)');
        }
    }
};
