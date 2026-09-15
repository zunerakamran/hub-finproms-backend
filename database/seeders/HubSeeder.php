<?php

namespace Database\Seeders;

use App\Models\Hub;
use Illuminate\Database\Seeder;

class HubSeeder extends Seeder
{
    public function run(): void
    {
        $slug = (string) config('hub.current_slug', 'shared');
        $isShared = $slug === 'shared';

        Hub::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $isShared ? 'Shared Hub' : str($slug)->replace(['-', '_'], ' ')->title()->toString(),
                'type' => $isShared ? Hub::TYPE_SHARED : Hub::TYPE_WHITE_LABEL,
                'is_active' => true,
                'primary_color' => null,
                'secondary_color' => null,
                'logo_url' => null,
                'favicon_url' => null,
                'checklist' => Hub::defaultChecklist(
                    $isShared ? Hub::TYPE_SHARED : Hub::TYPE_WHITE_LABEL
                ),
            ]
        );
    }
}
