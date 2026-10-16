<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ModuleDisasterSnapshot extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'module_disaster_snapshots';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_AUTO_BASELINE = 'auto_baseline';

    public const TRIGGER_PRE_ROLLBACK = 'pre_rollback';

    public const TRIGGER_RECOGNIZE = 'recognize';

    protected $fillable = [
        'hub_id',
        'module_key',
        'label',
        'trigger',
        'disk_path',
        'size_bytes',
        'row_counts',
        'module_enabled',
        'security_incident_id',
        'created_by_user_id',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'row_counts' => 'array',
            'module_enabled' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SecurityIncident::class, 'security_incident_id');
    }

    public function absolutePath(): ?string
    {
        if (! $this->disk_path) {
            return null;
        }

        return Storage::disk('local')->path($this->disk_path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $defs = Hub::CHECKLIST_DEFINITIONS[$this->module_key] ?? null;

        return [
            'id' => $this->id,
            'hub_id' => $this->hub_id,
            'module_key' => $this->module_key,
            'module_label' => is_array($defs) ? ($defs['label'] ?? $this->module_key) : $this->module_key,
            'label' => $this->label,
            'trigger' => $this->trigger,
            'size_bytes' => $this->size_bytes,
            'row_counts' => $this->row_counts ?? [],
            'module_enabled' => $this->module_enabled,
            'security_incident_id' => $this->security_incident_id,
            'created_by' => [
                'user_id' => $this->created_by_user_id,
                'name' => $this->created_by_name,
            ],
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
