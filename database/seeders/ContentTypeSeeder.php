<?php

namespace Database\Seeders;

use App\Models\ContentType;
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
            ContentType::updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
