<?php

namespace App\Models\WebsiteCompliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $table = 'wc_sections';

    protected $fillable = [
        'page_id',
        'template_id',
        'advisor_id',
        'template_request_id',
        'name',
        'section_key',
        'display_name',
        'is_visible',
        'content',
        'is_locked',
        'locked_by',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_locked' => 'boolean',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    public function lockedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class, 'section_id');
    }
}
