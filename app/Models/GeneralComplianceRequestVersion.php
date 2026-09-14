<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneralComplianceRequestVersion extends Model
{
    protected $table = 'general_compliance_request_versions';

    protected $fillable = [
        'request_id',
        'version_number',
        'description',
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
        return $this->belongsTo(GeneralComplianceRequest::class, 'request_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(GeneralComplianceRequestAttachment::class, 'version_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing('attachments');

        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'version_number' => $this->version_number,
            'description' => $this->description,
            'attachments' => $this->attachments
                ->map(fn (GeneralComplianceRequestAttachment $a) => $a->toApiArray())
                ->values()
                ->all(),
            'submitted_by' => $this->submitted_by,
            'submitted_at' => optional($this->submitted_at)?->toIso8601String(),
            'status' => $this->status,
            'feedback' => $this->feedback,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
        ];
    }
}
