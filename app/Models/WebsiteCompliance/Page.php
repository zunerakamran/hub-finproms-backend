<?php

namespace App\Models\WebsiteCompliance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $table = 'wc_pages';

    protected $fillable = [
        'template_id',
        'title',
        'slug',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'page_id');
    }
}
