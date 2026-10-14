<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Support\ComplianceSupportingFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class GeneralComplianceRequestAttachment extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'general_compliance_request_attachments';

    public const KIND_ATTACHMENT = 'attachment';

    public const KIND_SUPPORTING_FILE = 'supporting_file';

    protected $fillable = [
        'version_id',
        'kind',
        'original_name',
        'file_path',
        'file_url',
        'mime_type',
        'size_bytes',
        'is_encrypted',
        'sort_order',
        'uploaded_by_user_id',
        'uploaded_by_name',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'uploaded_by_user_id' => 'integer',
            'is_encrypted' => 'boolean',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GeneralComplianceRequestVersion::class, 'version_id');
    }

    /**
     * True when bytes are stored encrypted (gc_encrypted disk / enc/ path).
     * Legacy public-disk rows remain readable via the same download endpoint.
     */
    public function isStoredEncrypted(): bool
    {
        if ($this->is_encrypted) {
            return true;
        }

        $path = (string) ($this->file_path ?? '');

        return str_starts_with($path, 'enc/')
            || str_starts_with($path, 'general-compliance/enc/');
    }

    public function resolvedFileUrl(): ?string
    {
        // Encrypted (and preferred) path: authenticated API preview — never /storage/...
        if ($this->id && $this->isStoredEncrypted()) {
            return url('/api/gc/file/'.$this->id);
        }

        // Legacy plaintext public-disk rows: still serve via auth endpoint so the UI
        // can use one fetch-with-token pattern for all GC files.
        if ($this->id && filled($this->file_path)) {
            return url('/api/gc/file/'.$this->id);
        }

        if (filled($this->file_url)) {
            return $this->file_url;
        }

        if (! filled($this->file_path)) {
            return null;
        }

        if (str_starts_with($this->file_path, 'http://')
            || str_starts_with($this->file_path, 'https://')
            || str_starts_with($this->file_path, '/')) {
            return $this->file_path;
        }

        return Storage::disk('public')->url($this->file_path);
    }

    /**
     * Authenticated URL that forces Content-Disposition: attachment (when permitted).
     */
    public function resolvedDownloadUrl(): ?string
    {
        $preview = $this->resolvedFileUrl();
        if ($preview === null || ! $this->id) {
            return null;
        }

        // Only append for the authenticated GC file endpoint — not legacy public URLs.
        if (! str_contains($preview, '/api/gc/file/')) {
            return $preview;
        }

        return $preview.(str_contains($preview, '?') ? '&' : '?').'download=1';
    }

    /**
     * Whether the current actor may force-download this file (hub capability).
     */
    public function currentActorMayDownload(): bool
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        $hub = app(ActingHubService::class)->capabilityHub($user, 'gc_download_attachments');
        if (! $hub instanceof Hub) {
            $hub = app(HubService::class)->current();
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $role = $matrix->effectiveRoleFor($user);

        return $matrix->roleCan($hub, $role, 'gc_download_attachments');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $source = $this->source;
        $kind = $this->kind ?: self::KIND_ATTACHMENT;
        $canDownload = $this->currentActorMayDownload();

        return [
            'id' => $this->id,
            'version_id' => $this->version_id,
            'kind' => $kind,
            'original_name' => $this->original_name,
            'file_path' => $this->file_path,
            // file_url opens as in-hub preview (Content-Disposition: inline).
            'file_url' => $this->resolvedFileUrl(),
            'download_url' => $canDownload ? $this->resolvedDownloadUrl() : null,
            'can_download' => $canDownload,
            'is_encrypted' => $this->isStoredEncrypted(),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sort_order' => $this->sort_order,
            'uploaded_by_user_id' => $this->uploaded_by_user_id ? (int) $this->uploaded_by_user_id : null,
            'uploaded_by_name' => $this->uploaded_by_name,
            'source' => $source,
            'uploaded_by_role' => $kind === self::KIND_SUPPORTING_FILE
                ? ComplianceSupportingFiles::roleForSource($source)
                : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
