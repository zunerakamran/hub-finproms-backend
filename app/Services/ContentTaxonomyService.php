<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentType;
use App\Models\Post;
use App\Models\Tag;

/**
 * Keep content_types / categories / tags tables in sync with names used on posts.
 * Used by Central library one-by-one create, Excel import, and remote distribute.
 */
class ContentTaxonomyService
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $tags
     */
    public function ensureNames(?string $type, array $categories = [], array $tags = []): void
    {
        $type = trim((string) $type);
        if ($type !== '' && ! ContentType::query()->where('name', $type)->exists()) {
            ContentType::query()->create([
                'name' => $type,
                'slug' => str($type)->slug()->toString() ?: 'post',
            ]);
            Post::clearTypeSlugMap();
        }

        foreach ($categories as $name) {
            $name = trim((string) $name);
            if ($name === '' || Category::query()->where('name', $name)->exists()) {
                continue;
            }
            Category::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString() ?: 'category',
            ]);
        }

        foreach ($tags as $name) {
            $name = trim((string) $name);
            if ($name === '' || Tag::query()->where('name', $name)->exists()) {
                continue;
            }
            Tag::query()->create([
                'name' => $name,
                'slug' => str($name)->slug()->toString() ?: 'tag',
            ]);
        }
    }
}
