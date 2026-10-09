<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'icon_path',
    ];

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            if (blank($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::deleting(function (Category $category) {
            $category->deleteStoredIcon();
        });
    }

    /**
     * Absolute public URL for an uploaded category icon (null when none).
     */
    public function iconPublicUrl(): ?string
    {
        $path = trim((string) ($this->icon_path ?? ''));
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/api/media/'.ltrim($path, '/');
    }

    /**
     * @return array{id: int, name: string, slug: string, icon: ?string, icon_url: ?string, posts_count?: int}
     */
    public function toApiArray(?int $postsCount = null): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => filled($this->icon) ? (string) $this->icon : null,
            'icon_url' => $this->iconPublicUrl(),
        ];

        if ($postsCount !== null) {
            $payload['posts_count'] = $postsCount;
        }

        return $payload;
    }

    public function deleteStoredIcon(): void
    {
        $path = trim((string) ($this->icon_path ?? ''));
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return;
        }

        if (str_starts_with($path, 'categories/icons/') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
