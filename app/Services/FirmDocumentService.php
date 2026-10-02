<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmDocument;
use App\Models\FirmDocumentAttachment;
use App\Models\FirmDocumentMemberRight;
use App\Models\User;
use App\Support\ComplianceSupportingFiles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FirmDocumentService
{
    public function __construct(
        private readonly FirmDocumentAccessService $access,
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs,
    ) {}

    public function assertCan(User $user, Firm $firm, string $right): void
    {
        if (! $this->access->can($user, $firm, $right)) {
            throw new HttpException(403, 'You do not have permission to '.$right.' firm documents for this firm.');
        }
    }

    /**
     * @return LengthAwarePaginator<int, FirmDocument>
     */
    public function paginate(User $actor, Firm $firm, string $scope = 'active', int $perPage = 25): LengthAwarePaginator
    {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_VIEW);

        $query = FirmDocument::query()
            ->with(['attachments', 'uploader:id,name,email'])
            ->where('firm_id', $firm->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($scope === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif ($scope === 'all') {
            // no filter
        } else {
            $query->whereNull('archived_at');
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function create(
        User $actor,
        Firm $firm,
        string $title,
        ?string $description,
        array $files,
        ?Request $request = null,
    ): FirmDocument {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_ADD);

        if ($files === []) {
            throw ValidationException::withMessages([
                'attachments' => 'At least one attachment is required.',
            ]);
        }

        ComplianceSupportingFiles::assertWithinLimits($files, 'attachments');

        $document = DB::transaction(function () use ($actor, $firm, $title, $description, $files) {
            $document = FirmDocument::query()->create([
                'firm_id' => $firm->id,
                'title' => $title,
                'description' => $description,
                'uploaded_by' => $actor->id,
            ]);

            $this->storeAttachments($document, $files);

            return $document->load(['attachments', 'uploader:id,name,email']);
        });

        $this->activityLogs->log([
            'action' => 'firm.documents.add',
            'description' => 'Added firm document “'.$document->title.'” for “'.$firm->name.'”',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject' => $document,
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'document_id' => $document->id,
                'document_title' => $document->title,
                'attachment_count' => $document->attachments->count(),
            ],
        ]);

        return $document;
    }

    public function delete(User $actor, FirmDocument $document, ?Request $request = null): void
    {
        $firm = $document->firm ?? Firm::query()->findOrFail($document->firm_id);
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_DELETE);

        $payload = [
            'firm_id' => $firm->id,
            'firm_name' => $firm->name,
            'document_id' => $document->id,
            'document_title' => $document->title,
        ];

        DB::transaction(function () use ($document) {
            foreach ($document->attachments as $attachment) {
                $this->deleteStoredFile($attachment);
                $attachment->delete();
            }
            $document->delete();
        });

        $this->activityLogs->log([
            'action' => 'firm.documents.delete',
            'description' => 'Deleted firm document “'.$payload['document_title'].'” for “'.$firm->name.'”',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject_type' => FirmDocument::class,
            'subject_id' => $payload['document_id'],
            'request' => $request,
            'status_code' => 200,
            'properties' => $payload,
        ]);
    }

    public function archive(User $actor, FirmDocument $document, bool $archive = true, ?Request $request = null): FirmDocument
    {
        $firm = $document->firm ?? Firm::query()->findOrFail($document->firm_id);
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_ARCHIVE);

        if ($archive) {
            if ($document->isArchived()) {
                return $document->load(['attachments', 'uploader:id,name,email']);
            }
            $document->archived_at = now();
            $document->archived_by = $actor->id;
            $document->save();
            $action = 'firm.documents.archive';
            $description = 'Archived firm document “'.$document->title.'” for “'.$firm->name.'”';
        } else {
            if (! $document->isArchived()) {
                return $document->load(['attachments', 'uploader:id,name,email']);
            }
            $document->archived_at = null;
            $document->archived_by = null;
            $document->save();
            $action = 'firm.documents.unarchive';
            $description = 'Unarchived firm document “'.$document->title.'” for “'.$firm->name.'”';
        }

        $this->activityLogs->log([
            'action' => $action,
            'description' => $description,
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject' => $document,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'document_id' => $document->id,
                'document_title' => $document->title,
            ],
        ]);

        return $document->fresh(['attachments', 'uploader:id,name,email']);
    }

    /**
     * @return list<FirmDocumentMemberRight>
     */
    public function listMemberRights(User $actor, Firm $firm): array
    {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS);

        return FirmDocumentMemberRight::query()
            ->with('user:id,name,email')
            ->where('firm_id', $firm->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array{can_add?: bool, can_view?: bool, can_delete?: bool, can_archive?: bool}  $rights
     */
    public function setMemberRights(
        User $actor,
        Firm $firm,
        User $member,
        array $rights,
        ?Request $request = null,
    ): FirmDocumentMemberRight {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS);

        if ($member->firm_id === null || (int) $member->firm_id !== (int) $firm->id) {
            throw ValidationException::withMessages([
                'user_id' => 'Document rights can only be granted to members of this firm.',
            ]);
        }

        if ($this->access->isHeadOfFirm($member, $firm)) {
            throw ValidationException::withMessages([
                'user_id' => 'The Head of Firm already has all document rights.',
            ]);
        }

        $canAdd = (bool) ($rights['can_add'] ?? false);
        $canView = (bool) ($rights['can_view'] ?? false);
        $canDelete = (bool) ($rights['can_delete'] ?? false);
        $canArchive = (bool) ($rights['can_archive'] ?? false);

        $existing = FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->where('user_id', $member->id)
            ->first();

        if (! $canAdd && ! $canView && ! $canDelete && ! $canArchive) {
            if ($existing) {
                $existing->delete();
                $this->activityLogs->log([
                    'action' => 'firm.documents.member_rights.revoke',
                    'description' => 'Revoked firm document rights for '.$member->name.' on “'.$firm->name.'”',
                    'user' => $actor,
                    'hub' => $this->hubs->current(),
                    'subject' => $member,
                    'request' => $request,
                    'status_code' => 200,
                    'properties' => [
                        'firm_id' => $firm->id,
                        'firm_name' => $firm->name,
                        'target_user_id' => $member->id,
                        'target_user_name' => $member->name,
                    ],
                ]);
            }

            // Return empty rights row for API convenience.
            return new FirmDocumentMemberRight([
                'firm_id' => $firm->id,
                'user_id' => $member->id,
                'can_add' => false,
                'can_view' => false,
                'can_delete' => false,
                'can_archive' => false,
            ]);
        }

        $row = FirmDocumentMemberRight::query()->updateOrCreate(
            [
                'firm_id' => $firm->id,
                'user_id' => $member->id,
            ],
            [
                'can_add' => $canAdd,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
            ]
        );

        $this->activityLogs->log([
            'action' => 'firm.documents.member_rights.grant',
            'description' => 'Updated firm document rights for '.$member->name.' on “'.$firm->name.'”',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject' => $row,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'target_user_id' => $member->id,
                'target_user_name' => $member->name,
                'can_add' => $canAdd,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
            ],
        ]);

        return $row->load('user:id,name,email');
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeAttachments(FirmDocument $document, array $files): void
    {
        foreach (array_values($files) as $index => $file) {
            $path = $file->store('firm-documents/'.$document->firm_id, 'public');
            FirmDocumentAttachment::query()->create([
                'firm_document_id' => $document->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_url' => Storage::disk('public')->url($path),
                'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
                'size_bytes' => $file->getSize() ?: null,
                'sort_order' => $index,
            ]);
        }
    }

    private function deleteStoredFile(FirmDocumentAttachment $attachment): void
    {
        if (! filled($attachment->file_path)) {
            return;
        }

        if (str_starts_with($attachment->file_path, 'http://')
            || str_starts_with($attachment->file_path, 'https://')) {
            return;
        }

        try {
            Storage::disk('public')->delete($attachment->file_path);
        } catch (\Throwable) {
            // Best-effort cleanup.
        }
    }
}
