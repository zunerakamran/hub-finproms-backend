<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneralComplianceRequestVersion extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'general_compliance_request_versions';

    protected $fillable = [
        'request_id',
        'version_number',
        'description',
        'content_type',
        'submitted_by',
        'submitted_at',
        'status',
        'feedback',
        'future_feedback',
        'reviewed_by',
        'reviewed_at',
        'on_behalf_by_user_id',
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
        return $this->belongsTo(GeneralComplianceRequest::class, 'request_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Primary submission files (multipart field: attachments[]).
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(GeneralComplianceRequestAttachment::class, 'version_id')
            ->where('kind', GeneralComplianceRequestAttachment::KIND_ATTACHMENT)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * Supporting / evidence files (multipart field: supporting_files[]).
     */
    public function supportingFiles(): HasMany
    {
        return $this->hasMany(GeneralComplianceRequestAttachment::class, 'version_id')
            ->where('kind', GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * All files for this version (both kinds), used when copying across versions.
     */
    public function allFiles(): HasMany
    {
        return $this->hasMany(GeneralComplianceRequestAttachment::class, 'version_id')
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing(['attachments', 'supportingFiles']);

        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'version_number' => $this->version_number,
            'description' => $this->description,
            'content_type' => $this->content_type,
            'attachments' => $this->attachments
                ->map(fn (GeneralComplianceRequestAttachment $a) => $a->toApiArray())
                ->values()
                ->all(),
            'supporting_files' => $this->supportingFiles
                ->map(fn (GeneralComplianceRequestAttachment $a) => $a->toApiArray())
                ->values()
                ->all(),
            'submitted_by' => $this->submitted_by,
            'submitted_at' => optional($this->submitted_at)?->toIso8601String(),
            'status' => $this->status,
            // `feedback` = Remedial Feedback/notes (legacy key kept for clients).
            'feedback' => $this->feedback,
            'future_feedback' => $this->future_feedback,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
        ];
    }
}
