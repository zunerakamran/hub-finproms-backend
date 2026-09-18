<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SocialMediaComplianceRequestVersion extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'social_media_compliance_request_versions';

    protected $fillable = [
        'request_id',
        'version_number',
        'description',
        'image_path',
        'image_url',
        'submitted_by',
        'submitted_at',
        'status',
        'feedback',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(SocialMediaComplianceRequest::class, 'request_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function resolvedImageUrl(): ?string
    {
        if (filled($this->image_url)) {
            return $this->image_url;
        }

        if (! filled($this->image_path)) {
            return null;
        }

        if (str_starts_with($this->image_path, 'http://')
            || str_starts_with($this->image_path, 'https://')
            || str_starts_with($this->image_path, '/')) {
            return $this->image_path;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'version_number' => $this->version_number,
            'description' => $this->description,
            'image_path' => $this->image_path,
            'image_url' => $this->resolvedImageUrl(),
            'submitted_by' => $this->submitted_by,
            'submitted_at' => optional($this->submitted_at)?->toIso8601String(),
            'status' => $this->status,
            'feedback' => $this->feedback,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
        ];
    }
}
