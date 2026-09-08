<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedBigInteger('views_count')->default(0)->after('is_active');
            $table->unsignedBigInteger('reach_count')->default(0)->after('views_count');
            $table->unsignedBigInteger('buy_count')->default(0)->after('reach_count');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('name');
        });

        // Backfill slugs for existing categories
        if (Schema::hasTable('categories')) {
            $categories = DB::table('categories')->select('id', 'name')->get();
            foreach ($categories as $category) {
                $base = str($category->name)->slug()->toString() ?: 'type-'.$category->id;
                $slug = $base;
                $i = 1;
                while (DB::table('categories')->where('slug', $slug)->where('id', '!=', $category->id)->exists()) {
                    $slug = $base.'-'.$i;
                    $i++;
                }
                DB::table('categories')->where('id', $category->id)->update(['slug' => $slug]);
            }
        }

        Schema::create('post_reaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('viewer_key', 64);
            $table->timestamps();

            $table->unique(['post_id', 'viewer_key']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Rename legacy admin role → client_admin
        DB::table('users')->where('role', 'admin')->update(['role' => 'client_admin']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'client_admin')->update(['role' => 'admin']);

        Schema::dropIfExists('settings');
        Schema::dropIfExists('post_reaches');

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('slug');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['views_count', 'reach_count', 'buy_count']);
        });
    }
};
