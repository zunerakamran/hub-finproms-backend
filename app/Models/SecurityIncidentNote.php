<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityIncidentNote extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'security_incident_notes';

    protected $fillable = [
        'security_incident_id',
        'user_id',
        'author_name',
        'body',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SecurityIncident::class, 'security_incident_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'security_incident_id' => $this->security_incident_id,
            'user_id' => $this->user_id,
            'author_name' => $this->author_name,
            'body' => $this->body,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
