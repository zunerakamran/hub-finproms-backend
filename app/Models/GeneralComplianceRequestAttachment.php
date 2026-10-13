<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use App\Support\ComplianceSupportingFiles;
use App\Support\GeneralComplianceEncryptedFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function isStoredEncrypted(): bool
    {
        if ($this->is_encrypted) {
            return true;
        }

        $path = (string) ($this->file_path ?? '');

        return str_starts_with($path, GeneralComplianceEncryptedFiles::DIR.'/');
    }

    /**
     * Authenticated download path for encrypted (and preferred for all) GC files.
     *
     * Returns a same-origin relative /api/... path so the SPA can fetch with
     * credentials (cookie session). Absolute APP_URL links break when opened in
     * a bare tab without auth and caused 500s on null user.
     * Legacy public URLs remain as a fallback until backfill.
     */
    public function resolvedFileUrl(): ?string
    {
        if ($this->id && ($this->isStoredEncrypted() || ! filled($this->file_url))) {
            // Short path preferred on LiteSpeed / shared hosts (see downloadAttachment).
            return '/api/gc/file/'.$this->id;
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
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $source = $this->source;
        $kind = $this->kind ?: self::KIND_ATTACHMENT;
        $encrypted = $this->isStoredEncrypted();

        return [
            'id' => $this->id,
            'version_id' => $this->version_id,
            'kind' => $kind,
            'original_name' => $this->original_name,
            // Do not expose private storage paths for encrypted files.
            'file_path' => $encrypted ? null : $this->file_path,
            'file_url' => $this->resolvedFileUrl(),
            'is_encrypted' => $encrypted,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sort_order' => $this->sort_order,
            'uploaded_by_user_id' => $this->uploaded_by_user_id ? (int) $this->uploaded_by_user_id : null,
            'uploaded_by_name' => $this->uploaded_by_name,
            'source' => $source,
            // Role label applies to supporting files in version history.
            'uploaded_by_role' => $kind === self::KIND_SUPPORTING_FILE
                ? ComplianceSupportingFiles::roleForSource($source)
                : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
