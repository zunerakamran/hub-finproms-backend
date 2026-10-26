<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvisorImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_FAILED = 'failed';

    public const KIND_IMPORT = 'import';

    public const KIND_SUBMISSION = 'submission';

    protected $fillable = [
        'hub_id',
        'imported_by_user_id',
        'imported_by_name',
        'imported_by_email',
        'original_filename',
        'stored_path',
        'status',
        'kind',
        'created_count',
        'updated_count',
        'reactivated_count',
        'skipped_count',
        'submitted_user_count',
        'created_users',
        'updated_users',
        'reactivated_users',
        'skipped_rows',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'reactivated_count' => 'integer',
            'skipped_count' => 'integer',
            'submitted_user_count' => 'integer',
            'created_users' => 'array',
            'updated_users' => 'array',
            'reactivated_users' => 'array',
            'skipped_rows' => 'array',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    public function isPendingSubmission(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->kind === self::KIND_SUBMISSION;
    }

    /**
     * @param  array{include_details?: bool, can_download?: bool, can_import?: bool}  $options
     * @return array<string, mixed>
     */
    public function toApiArray(array $options = []): array
    {
        $includeDetails = array_key_exists('include_details', $options)
            ? (bool) $options['include_details']
            : true;
        $canDownload = (bool) ($options['can_download'] ?? false);
        $canImport = (bool) ($options['can_import'] ?? false);

        $pendingUsers = $includeDetails ? $this->pendingUserRows() : [];
        $userCount = $this->isPendingSubmission()
            ? (int) ($this->submitted_user_count ?: count($this->pendingUserRows()))
            : ((int) $this->created_count + (int) $this->updated_count + (int) $this->reactivated_count);

        $hasStoredFile = filled($this->stored_path);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'kind' => $this->kind ?: self::KIND_IMPORT,
            'original_filename' => $this->original_filename,
            'message' => $includeDetails ? $this->message : null,
            'has_stored_file' => $hasStoredFile,
            'can_download_submission' => $canDownload && $hasStoredFile,
            'can_import_submission' => $canImport && $this->isPendingSubmission() && $hasStoredFile,
            'imported_by' => [
                'id' => $this->imported_by_user_id,
                'name' => $this->imported_by_name,
                'email' => $this->imported_by_email,
            ],
            'submitted_by' => [
                'id' => $this->imported_by_user_id,
                'name' => $this->imported_by_name,
                'email' => $this->imported_by_email,
            ],
            'user_count' => $userCount,
            'submitted_user_count' => (int) $this->submitted_user_count,
            'summary' => [
                'created' => $this->created_count,
                'updated' => $this->updated_count,
                'reactivated' => $this->reactivated_count,
                'skipped' => $this->skipped_count,
                'users' => $userCount,
            ],
            'created' => $includeDetails ? $this->sanitizeUserRows($this->created_users ?? []) : [],
            'updated' => $includeDetails ? $this->sanitizeUserRows($this->updated_users ?? []) : [],
            'reactivated' => $includeDetails ? $this->sanitizeUserRows($this->reactivated_users ?? []) : [],
            'pending_users' => $pendingUsers,
            'skipped' => $includeDetails ? ($this->skipped_rows ?? []) : [],
            'details_available' => $includeDetails,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{name: ?string, email: ?string, role: ?string, firm: ?string}>
     */
    private function pendingUserRows(): array
    {
        if (! $this->isPendingSubmission()) {
            return [];
        }

        return array_values(array_merge(
            $this->sanitizeUserRows($this->created_users ?? []),
            $this->sanitizeUserRows($this->updated_users ?? []),
            $this->sanitizeUserRows($this->reactivated_users ?? [])
        ));
    }

    /**
     * Strip temporary passwords from stored history rows.
     *
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function sanitizeUserRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            unset($row['temporary_password'], $row['password'], $row['user']);
            $out[] = [
                'name' => $row['name'] ?? null,
                'email' => $row['email'] ?? null,
                'role' => $row['role'] ?? null,
                'firm' => $row['firm'] ?? null,
            ];
        }

        return $out;
    }
}
