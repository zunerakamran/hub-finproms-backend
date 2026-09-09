<?php

namespace Database\Seeders;

use App\Models\Hub;
use Illuminate\Database\Seeder;

class HubSeeder extends Seeder
{
    public function run(): void
    {
        Hub::query()->updateOrCreate(
            ['slug' => 'shared'],
            [
                'name' => 'Shared Hub',
                'type' => Hub::TYPE_SHARED,
                'is_active' => true,
                'primary_color' => null,
                'secondary_color' => null,
                'logo_url' => null,
                'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            ]
        );
    }
}
