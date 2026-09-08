<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class ContentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Post', 'slug' => 'post'],
            ['name' => 'Reel', 'slug' => 'reel'],
        ];

        foreach ($types as $type) {
            Category::updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
