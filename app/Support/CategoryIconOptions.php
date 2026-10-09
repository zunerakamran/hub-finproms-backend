<?php

namespace App\Support;

/**
 * Curated Font Awesome icon keys for SM Template categories.
 * Keys must match the frontend categoryIcons list.
 */
class CategoryIconOptions
{
    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'briefcase',
            'building',
            'chart-line',
            'chart-pie',
            'coins',
            'comments',
            'file-alt',
            'file-invoice-dollar',
            'globe',
            'handshake',
            'heart',
            'home',
            'image',
            'landmark',
            'layer-group',
            'lightbulb',
            'newspaper',
            'palette',
            'percentage',
            'play-circle',
            'seedling',
            'shield-alt',
            'shopping-bag',
            'star',
            'tags',
            'tasks',
            'th-large',
            'users',
            'video',
            'bullhorn',
            'calendar',
            'camera',
            'graduation-cap',
            'rocket',
            'trophy',
        ];
    }

    public static function isValid(?string $key): bool
    {
        $key = trim((string) $key);
        if ($key === '') {
            return true;
        }

        return in_array($key, self::keys(), true);
    }
}
