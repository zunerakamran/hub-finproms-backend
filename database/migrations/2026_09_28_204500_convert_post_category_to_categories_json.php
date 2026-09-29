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
            $table->json('categories')->nullable()->after('type');
        });

        $posts = DB::table('posts')->select('id', 'category')->get();
        foreach ($posts as $post) {
            $list = filled($post->category) ? [(string) $post->category] : [];
            DB::table('posts')->where('id', $post->id)->update([
                'categories' => json_encode(array_values($list)),
            ]);
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('category')->nullable()->after('type');
        });

        $posts = DB::table('posts')->select('id', 'categories')->get();
        foreach ($posts as $post) {
            $list = json_decode((string) $post->categories, true);
            $first = is_array($list) && $list !== [] ? (string) $list[0] : '';
            DB::table('posts')->where('id', $post->id)->update([
                'category' => $first,
            ]);
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('categories');
        });
    }
};
