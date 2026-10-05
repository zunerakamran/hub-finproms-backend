<?php

namespace Database\Seeders;

use App\Models\Hub;
use Illuminate\Database\Seeder;

class HubSeeder extends Seeder
{
    public function run(): void
    {
        $slug = (string) config('hub.current_slug', 'shared');
        $type = $this->resolveType($slug);

        $name = match ($type) {
            Hub::TYPE_CENTRAL => 'Central Hub Controller',
            Hub::TYPE_SHARED => $slug === 'shared'
                ? 'Shared Hub'
                : str($slug)->replace(['-', '_'], ' ')->title()->toString(),
            default => str($slug)->replace(['-', '_'], ' ')->title()->toString(),
        };

        Hub::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'type' => $type,
                'is_active' => true,
                'primary_color' => null,
                'secondary_color' => null,
                'accent_color' => null,
                'logo_url' => null,
                'favicon_url' => null,
                'checklist' => Hub::defaultChecklist($type),
            ]
        );
    }

    private function resolveType(string $slug): string
    {
        $configured = strtolower(trim((string) config('hub.type', '')));
        if (in_array($configured, Hub::TYPES, true)) {
            return $configured;
        }

        if ($slug === 'central') {
            return Hub::TYPE_CENTRAL;
        }

        // Convention: slug "shared" or "shared-*" → Shared content hub (many allowed).
        if ($slug === 'shared' || str_starts_with($slug, 'shared-')) {
            return Hub::TYPE_SHARED;
        }

        return Hub::TYPE_WHITE_LABEL;
    }
}
