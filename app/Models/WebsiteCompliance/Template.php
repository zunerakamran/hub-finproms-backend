<?php

namespace App\Models\WebsiteCompliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'wc_templates';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'thumbnail_url',
        'preview_url',
        'dummy_content',
        'color_schemes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'color_schemes' => 'array',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'template_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'template_id');
    }
}
