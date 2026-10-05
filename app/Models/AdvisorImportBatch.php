<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvisorImportBatch extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hub_id',
        'imported_by_user_id',
        'imported_by_name',
        'imported_by_email',
        'original_filename',
        'status',
        'created_count',
        'updated_count',
        'reactivated_count',
        'skipped_count',
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

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'message' => $this->message,
            'imported_by' => [
                'id' => $this->imported_by_user_id,
                'name' => $this->imported_by_name,
                'email' => $this->imported_by_email,
            ],
            'summary' => [
                'created' => $this->created_count,
                'updated' => $this->updated_count,
                'reactivated' => $this->reactivated_count,
                'skipped' => $this->skipped_count,
            ],
            'created' => $this->sanitizeUserRows($this->created_users ?? []),
            'updated' => $this->sanitizeUserRows($this->updated_users ?? []),
            'reactivated' => $this->sanitizeUserRows($this->reactivated_users ?? []),
            'skipped' => $this->skipped_rows ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
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
