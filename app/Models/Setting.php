<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const KEY_NEW_BANNER_DAYS = 'new_banner_days';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("setting:{$key}", 60, function () use ($key) {
            return static::query()->where('key', $key)->first();
        });

        if (! $setting) {
            return $default;
        }

        return $setting->value ?? $default;
    }

    public static function setValue(string $key, mixed $value): self
    {
        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]
        );

        Cache::forget("setting:{$key}");

        return $setting;
    }

    public static function newBannerDays(): int
    {
        return max(0, (int) static::getValue(self::KEY_NEW_BANNER_DAYS, 7));
    }
}
