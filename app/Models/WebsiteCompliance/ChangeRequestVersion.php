<?php

namespace App\Models\WebsiteCompliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChangeRequestVersion extends Model
{
    protected $table = 'wc_change_request_versions';

    protected $fillable = [
        'request_id',
        'version_number',
        'proposed_content',
        'status',
        'feedback',
        'submitted_by',
        'submitted_at',
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
        return $this->belongsTo(ChangeRequest::class, 'request_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
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
            'proposed_content' => $this->proposed_content,
            'status' => $this->status,
            'feedback' => $this->feedback,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => optional($this->submitted_at)?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
        ];
    }
}
