<?php

namespace App\Services;

use App\Models\AdvisorImportBatch;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AdvisorImportHistoryService
{
    /**
     * Persist a completed (or awaiting-payment) import batch for the Import Users history UI.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Hub $hub,
        User $actor,
        array $payload,
        ?string $originalFilename = null,
        string $status = AdvisorImportBatch::STATUS_COMPLETED
    ): AdvisorImportBatch {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $created = $this->normalizeUserRows($payload['created'] ?? $payload['preview']['created'] ?? []);
        $updated = $this->normalizeUserRows($payload['updated'] ?? $payload['preview']['updated'] ?? []);
        $reactivated = $this->normalizeUserRows($payload['reactivated'] ?? $payload['preview']['reactivated'] ?? []);
        $skipped = is_array($payload['skipped'] ?? null) ? array_values($payload['skipped']) : [];

        return AdvisorImportBatch::query()->create([
            'hub_id' => $hub->id,
            'imported_by_user_id' => $actor->id,
            'imported_by_name' => $actor->name,
            'imported_by_email' => $actor->email,
            'original_filename' => $originalFilename,
            'status' => $status,
            'kind' => AdvisorImportBatch::KIND_IMPORT,
            'created_count' => (int) ($summary['created'] ?? count($created)),
            'updated_count' => (int) ($summary['updated'] ?? count($updated)),
            'reactivated_count' => (int) ($summary['reactivated'] ?? count($reactivated)),
            'skipped_count' => (int) ($summary['skipped'] ?? count($skipped)),
            'submitted_user_count' => 0,
            'created_users' => $created,
            'updated_users' => $updated,
            'reactivated_users' => $reactivated,
            'skipped_rows' => $skipped,
            'message' => isset($payload['message']) ? (string) $payload['message'] : null,
        ]);
    }

    /**
     * Queue a filled Excel sheet for an importer (does not create users yet).
     *
     * @param  array<string, mixed>  $plan  Result of AdvisorImportService::buildPlan()
     */
    public function recordSubmission(
        Hub $hub,
        User $submitter,
        array $plan,
        UploadedFile $file,
        string $storedPath
    ): AdvisorImportBatch {
        $summary = is_array($plan['summary'] ?? null) ? $plan['summary'] : [];
        $preview = is_array($plan['preview'] ?? null) ? $plan['preview'] : [];
        $created = $this->normalizeUserRows($preview['created'] ?? []);
        $updated = $this->normalizeUserRows($preview['updated'] ?? []);
        $reactivated = $this->normalizeUserRows($preview['reactivated'] ?? []);
        $skipped = is_array($plan['skipped'] ?? null) ? array_values($plan['skipped']) : [];

        $userCount = count($created) + count($updated) + count($reactivated);
        if ($userCount < 1) {
            $userCount = (int) ($summary['created'] ?? 0)
                + (int) ($summary['updated'] ?? 0)
                + (int) ($summary['reactivated'] ?? 0);
        }

        return AdvisorImportBatch::query()->create([
            'hub_id' => $hub->id,
            'imported_by_user_id' => $submitter->id,
            'imported_by_name' => $submitter->name,
            'imported_by_email' => $submitter->email,
            'original_filename' => $file->getClientOriginalName() ?: 'import.xlsx',
            'stored_path' => $storedPath,
            'status' => AdvisorImportBatch::STATUS_PENDING,
            'kind' => AdvisorImportBatch::KIND_SUBMISSION,
            'created_count' => (int) ($summary['created'] ?? count($created)),
            'updated_count' => (int) ($summary['updated'] ?? count($updated)),
            'reactivated_count' => (int) ($summary['reactivated'] ?? count($reactivated)),
            'skipped_count' => (int) ($summary['skipped'] ?? count($skipped)),
            'submitted_user_count' => $userCount,
            'created_users' => $created,
            'updated_users' => $updated,
            'reactivated_users' => $reactivated,
            'skipped_rows' => $skipped,
            'message' => sprintf(
                'Excel sheet sent for import (%d user%s pending). Waiting for someone with Import advisors.',
                $userCount,
                $userCount === 1 ? '' : 's'
            ),
        ]);
    }

    public function deleteStoredFile(?string $path): void
    {
        if (! filled($path)) {
            return;
        }
        try {
            Storage::disk('local')->delete($path);
        } catch (\Throwable) {
            // ignore cleanup failures
        }
    }

    /**
     * @return LengthAwarePaginator<int, AdvisorImportBatch>
     */
    public function paginate(Hub $hub, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        return AdvisorImportBatch::query()
            ->where('hub_id', $hub->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  mixed  $rows
     * @return list<array{name: ?string, email: ?string, role: ?string, firm: ?string}>
     */
    private function normalizeUserRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'name' => isset($row['name']) ? (string) $row['name'] : null,
                'email' => isset($row['email']) ? (string) $row['email'] : null,
                'role' => isset($row['role']) ? (string) $row['role'] : null,
                'firm' => isset($row['firm']) ? (string) $row['firm'] : null,
            ];
        }

        return $out;
    }
}
