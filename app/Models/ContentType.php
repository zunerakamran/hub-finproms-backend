<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ContentType extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    protected static function booted(): void
    {
        static::saving(function (ContentType $type) {
            if (blank($type->slug)) {
                $type->slug = Str::slug($type->name);
            }
        });
    }

    public function isReel(): bool
    {
        return in_array(strtolower((string) $this->slug), ['reel', 'reels'], true);
    }
}
