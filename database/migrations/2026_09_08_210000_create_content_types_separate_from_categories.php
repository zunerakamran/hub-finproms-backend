<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('type')->nullable()->after('description');
        });

        // Backfill: if a post's category matches a known type name/slug, use it as type
        $known = DB::table('categories')
            ->where(function ($query) {
                $query->whereIn('slug', ['post', 'reel', 'reels'])
                    ->orWhereIn('name', ['Post', 'Reel', 'Posts', 'Reels']);
            })
            ->pluck('name', 'slug');

        foreach ($known as $slug => $name) {
            DB::table('content_types')->updateOrInsert(
                ['slug' => $slug === 'reels' ? 'reel' : $slug],
                [
                    'name' => $name === 'Reels' ? 'Reel' : ($name === 'Posts' ? 'Post' : $name),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // Default types if none
        if (DB::table('content_types')->count() === 0) {
            DB::table('content_types')->insert([
                ['name' => 'Post', 'slug' => 'post', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Reel', 'slug' => 'reel', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $defaultType = DB::table('content_types')->where('slug', 'post')->value('name')
            ?? DB::table('content_types')->value('name')
            ?? 'Post';

        $typeNames = DB::table('content_types')->pluck('name')->all();
        $typeSlugs = DB::table('content_types')->pluck('name', 'slug')->all();

        $posts = DB::table('posts')->select('id', 'category', 'type')->get();
        foreach ($posts as $post) {
            $typeName = $defaultType;
            $category = (string) $post->category;

            if (in_array($category, $typeNames, true)) {
                $typeName = $category;
            } else {
                foreach ($typeSlugs as $slug => $name) {
                    if (strcasecmp($category, (string) $slug) === 0 || strcasecmp($category, (string) $name) === 0) {
                        $typeName = $name;
                        break;
                    }
                }
            }

            DB::table('posts')->where('id', $post->id)->update(['type' => $typeName]);
        }

        // Remove Post/Reel rows from categories table (they belong in content_types)
        DB::table('categories')
            ->where(function ($query) {
                $query->whereIn('slug', ['post', 'reel', 'reels'])
                    ->orWhereIn('name', ['Post', 'Reel', 'Posts', 'Reels']);
            })
            ->delete();
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::dropIfExists('content_types');
    }
};
