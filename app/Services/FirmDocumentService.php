<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmDocument;
use App\Models\FirmDocumentAttachment;
use App\Models\FirmDocumentCategory;
use App\Models\FirmDocumentFirmRight;
use App\Models\FirmDocumentFolder;
use App\Models\FirmDocumentMemberRight;
use App\Models\Hub;
use App\Models\User;
use App\Support\ComplianceSupportingFiles;
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

    public function assertCan(User $user, Firm $firm, string $right, ?Hub $hub = null, ?FirmDocument $document = null): void
    {
        if (! $this->access->can($user, $firm, $right, $hub, $document)) {
            if (! $this->access->functionalityEnabled($hub)
                && ! $this->access->isHeadOfFirm($user, $firm)
            ) {
                throw new HttpException(403, 'Firm documents are disabled for this hub. Enable Functionalities → Firm documents first.');
            }

            throw new HttpException(403, 'You do not have permission to '.$right.' firm documents for this firm.');
        }
    }

    /**
     * Nested folder tree + documents for the firm library.
     *
     * @return array{
     *   folders: list<array<string, mixed>>,
     *   documents: list<array<string, mixed>>,
     *   unfiled_documents: list<array<string, mixed>>
     * }
     */
    public function library(User $actor, Firm $firm, string $scope = 'active', ?Hub $hub = null): array
    {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_VIEW, $hub);

        $query = FirmDocument::query()
            ->with(['attachments', 'uploader:id,name,email', 'folder:id,name,parent_id', 'category:id,name,slug'])
            ->where('firm_id', $firm->id)
            ->orderBy('title')
            ->orderBy('id');

        if ($scope === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif ($scope !== 'all') {
            $query->whereNull('archived_at');
        }

        if (! $this->access->seesAllFirmDocuments($actor, $firm, $hub)) {
            $ids = $this->access->visibleDocumentIds($actor, $firm);
            $query->whereIn('id', $ids === [] ? [0] : $ids);
        }

        $documents = $query->get();

        $serialized = $documents->map(function (FirmDocument $doc) use ($actor, $firm, $hub) {
            return $doc->toApiArray($this->access->documentRightsFor($actor, $firm, $doc, $hub));
        })->values();

        $folders = FirmDocumentFolder::query()
            ->where('firm_id', $firm->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $byParent = $folders->groupBy(fn (FirmDocumentFolder $f) => $f->parent_id === null ? 'root' : (string) $f->parent_id);
        $docsByFolder = $serialized->groupBy(fn (array $d) => $d['folder_id'] === null ? 'none' : (string) $d['folder_id']);

        $buildFolder = function (FirmDocumentFolder $folder) use (&$buildFolder, $byParent, $docsByFolder): array {
            $childFolders = ($byParent->get((string) $folder->id) ?? collect())
                ->map(fn (FirmDocumentFolder $child) => $buildFolder($child))
                ->values()
                ->all();

            $folderDocs = ($docsByFolder->get((string) $folder->id) ?? collect())->values()->all();

            return array_merge($folder->toApiArray(false), [
                'children' => $childFolders,
                'documents' => $folderDocs,
                'document_count' => count($folderDocs) + collect($childFolders)->sum('document_count'),
            ]);
        };

        $tree = ($byParent->get('root') ?? collect())
            ->map(fn (FirmDocumentFolder $folder) => $buildFolder($folder))
            ->values()
            ->all();

        $unfiled = ($docsByFolder->get('none') ?? collect())->values()->all();

        // Non-central firms also see Central / Network documents shared to them.
        $sharedFolder = null;
        if (! $firm->isCentral()) {
            $sharedIds = $this->access->sharedCentralDocumentIdsForFirm($firm);
            if ($sharedIds !== []) {
                $sharedQuery = FirmDocument::query()
                    ->with(['attachments', 'uploader:id,name,email', 'folder:id,name,parent_id', 'category:id,name,slug', 'firm:id,name,is_central'])
                    ->whereIn('id', $sharedIds)
                    ->orderBy('title')
                    ->orderBy('id');

                if ($scope === 'archived') {
                    $sharedQuery->whereNotNull('archived_at');
                } elseif ($scope !== 'all') {
                    $sharedQuery->whereNull('archived_at');
                }

                $sharedDocs = $sharedQuery->get()->map(function (FirmDocument $doc) use ($actor, $firm, $hub) {
                    $payload = $doc->toApiArray($this->access->documentRightsFor($actor, $firm, $doc, $hub));
                    $payload['shared_from'] = [
                        'firm_id' => (int) $doc->firm_id,
                        'firm_name' => $doc->firm?->name ?? Firm::CENTRAL_DEFAULT_NAME,
                        'is_central' => true,
                    ];

                    return $payload;
                })->values()->all();

                if ($sharedDocs !== []) {
                    $sharedFolder = [
                        'id' => -1,
                        'firm_id' => (int) $firm->id,
                        'parent_id' => null,
                        'name' => 'Shared from '.Firm::CENTRAL_DEFAULT_NAME,
                        'is_shared_bucket' => true,
                        'children' => [],
                        'documents' => $sharedDocs,
                        'document_count' => count($sharedDocs),
                    ];
                    $tree = array_merge([$sharedFolder], $tree);
                    $serialized = $serialized->merge($sharedDocs);
                }
            }
        }

        return [
            'folders' => $tree,
            'documents' => $serialized->values()->all(),
            'unfiled_documents' => $unfiled,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFolders(User $actor, Firm $firm, ?Hub $hub = null): array
    {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_VIEW, $hub);

        $folders = FirmDocumentFolder::query()
            ->where('firm_id', $firm->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $folders->map(fn (FirmDocumentFolder $f) => $f->toApiArray(false))->values()->all();
    }

    public function createFolder(
        User $actor,
        Firm $firm,
        string $name,
        ?int $parentId = null,
        ?Request $request = null,
        ?Hub $hub = null,
    ): FirmDocumentFolder {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_ADD, $hub);

        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Folder name is required.',
            ]);
        }

        if ($parentId !== null) {
            $parent = FirmDocumentFolder::query()
                ->where('firm_id', $firm->id)
                ->where('id', $parentId)
                ->first();
            if (! $parent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Parent folder not found for this firm.',
                ]);
            }
        }

        $folder = FirmDocumentFolder::query()->create([
            'firm_id' => $firm->id,
            'parent_id' => $parentId,
            'name' => $name,
            'created_by' => $actor->id,
        ]);

        $this->activityLogs->log([
            'action' => 'firm.documents.folder.create',
            'description' => 'Created firm document folder “'.$folder->name.'” for “'.$firm->name.'”',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject' => $folder,
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'folder_id' => $folder->id,
                'folder_name' => $folder->name,
                'parent_id' => $parentId,
            ],
        ]);

        return $folder;
    }

    /**
     * Resolve folder_id from an existing id, or create a new folder / nested path.
     *
     * @param  array{folder_id?: mixed, folder_name?: mixed, parent_folder_id?: mixed}  $input
     */
    public function resolveFolderId(User $actor, Firm $firm, array $input, ?Request $request = null, ?Hub $hub = null): ?int
    {
        if (! empty($input['folder_id'])) {
            $folder = FirmDocumentFolder::query()
                ->where('firm_id', $firm->id)
                ->where('id', (int) $input['folder_id'])
                ->first();
            if (! $folder) {
                throw ValidationException::withMessages([
                    'folder_id' => 'Folder not found for this firm.',
                ]);
            }

            return (int) $folder->id;
        }

        $folderName = isset($input['folder_name']) ? trim((string) $input['folder_name']) : '';
        if ($folderName === '') {
            return null;
        }

        $parentId = isset($input['parent_folder_id']) && $input['parent_folder_id'] !== '' && $input['parent_folder_id'] !== null
            ? (int) $input['parent_folder_id']
            : null;

        $folder = $this->createFolder($actor, $firm, $folderName, $parentId, $request, $hub);

        return (int) $folder->id;
    }

    public function assertValidCategoryId(mixed $categoryId): ?int
    {
        if ($categoryId === null || $categoryId === '') {
            return null;
        }

        $id = (int) $categoryId;
        if (! FirmDocumentCategory::query()->where('id', $id)->exists()) {
            throw ValidationException::withMessages([
                'category_id' => 'Select a valid category.',
            ]);
        }

        return $id;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  array{folder_id?: mixed, folder_name?: mixed, parent_folder_id?: mixed, category_id?: mixed}  $meta
     */
    public function create(
        User $actor,
        Firm $firm,
        string $title,
        ?string $description,
        array $files,
        array $meta = [],
        ?Request $request = null,
        ?Hub $hub = null,
    ): FirmDocument {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_ADD, $hub);

        if ($files === []) {
            throw ValidationException::withMessages([
                'attachments' => 'One attachment is required.',
            ]);
        }

        if (count($files) > 1) {
            throw ValidationException::withMessages([
                'attachments' => 'Only one attachment is allowed per document.',
            ]);
        }

        ComplianceSupportingFiles::assertWithinLimits($files, 'attachments');

        $folderId = $this->resolveFolderId($actor, $firm, $meta, $request, $hub);
        $categoryId = $this->assertValidCategoryId($meta['category_id'] ?? null);

        $document = DB::transaction(function () use ($actor, $firm, $title, $description, $files, $folderId, $categoryId) {
            $document = FirmDocument::query()->create([
                'firm_id' => $firm->id,
                'folder_id' => $folderId,
                'category_id' => $categoryId,
                'title' => $title,
                'description' => $description,
                'uploaded_by' => $actor->id,
            ]);

            $this->storeAttachments($document, $files);

            return $document->load(['attachments', 'uploader:id,name,email', 'folder:id,name,parent_id', 'category:id,name,slug']);
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
                'folder_id' => $folderId,
                'category_id' => $categoryId,
                'attachment_count' => $document->attachments->count(),
            ],
        ]);

        return $document;
    }

    public function delete(User $actor, FirmDocument $document, ?Request $request = null, ?Hub $hub = null): void
    {
        $firm = $document->firm ?? Firm::query()->findOrFail($document->firm_id);
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_DELETE, $hub, $document);

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

    public function archive(User $actor, FirmDocument $document, bool $archive = true, ?Request $request = null, ?Hub $hub = null): FirmDocument
    {
        $firm = $document->firm ?? Firm::query()->findOrFail($document->firm_id);
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_ARCHIVE, $hub, $document);

        if ($archive) {
            if ($document->isArchived()) {
                return $document->load(['attachments', 'uploader:id,name,email', 'folder:id,name,parent_id', 'category:id,name,slug']);
            }
            $document->archived_at = now();
            $document->archived_by = $actor->id;
            $document->save();
            $action = 'firm.documents.archive';
            $description = 'Archived firm document “'.$document->title.'” for “'.$firm->name.'”';
        } else {
            if (! $document->isArchived()) {
                return $document->load(['attachments', 'uploader:id,name,email', 'folder:id,name,parent_id', 'category:id,name,slug']);
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

        return $document->fresh(['attachments', 'uploader:id,name,email', 'folder:id,name,parent_id', 'category:id,name,slug']);
    }

    /**
     * Access-rights payload for the key-icon popup.
     * Central / Network documents → firm rows; other firms → member rows.
     *
     * @return array{mode: string, members: list<array<string, mixed>>, firms: list<array<string, mixed>>}
     */
    public function listDocumentAccessRights(User $actor, Firm $firm, FirmDocument $document, ?Hub $hub = null): array
    {
        if ((int) $document->firm_id !== (int) $firm->id) {
            throw ValidationException::withMessages([
                'document_id' => 'Document does not belong to this firm.',
            ]);
        }

        if ($firm->isCentral()) {
            if (! $this->access->canManageFirmAccess($actor, $firm, $hub)) {
                throw new HttpException(403, 'You do not have permission to manage firm access for Central / Network documents.');
            }

            return [
                'mode' => 'firms',
                'members' => [],
                'firms' => $this->listDocumentFirmRights($document),
            ];
        }

        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS, $hub);

        return [
            'mode' => 'members',
            'members' => $this->listDocumentMemberRightsRows($firm, $document),
            'firms' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDocumentMemberRights(User $actor, Firm $firm, FirmDocument $document, ?Hub $hub = null): array
    {
        return $this->listDocumentAccessRights($actor, $firm, $document, $hub)['members'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listDocumentMemberRightsRows(Firm $firm, FirmDocument $document): array
    {
        $rows = FirmDocumentMemberRight::query()
            ->with('user:id,name,email')
            ->where('firm_document_id', $document->id)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (FirmDocumentMemberRight $r) => (int) $r->user_id);

        $members = User::query()
            ->where('firm_id', $firm->id)
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'firm_id']);

        return $members->map(function (User $member) use ($firm, $rows) {
            $grant = $rows->get((int) $member->id);
            $isHead = $firm->head_user_id && (int) $firm->head_user_id === (int) $member->id;

            return [
                'id' => (int) $member->id,
                'name' => (string) $member->name,
                'email' => (string) $member->email,
                'is_firm_head' => $isHead,
                'can_add' => $isHead ? true : (bool) ($grant?->can_add),
                'can_view' => $isHead ? true : (bool) ($grant?->can_view),
                'can_delete' => $isHead ? true : (bool) ($grant?->can_delete),
                'can_archive' => $isHead ? true : (bool) ($grant?->can_archive),
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listDocumentFirmRights(FirmDocument $document): array
    {
        $grants = FirmDocumentFirmRight::query()
            ->where('firm_document_id', $document->id)
            ->get()
            ->keyBy(fn (FirmDocumentFirmRight $r) => (int) $r->grantee_firm_id);

        return Firm::query()
            ->where('is_central', false)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'is_central'])
            ->map(function (Firm $target) use ($grants) {
                $grant = $grants->get((int) $target->id);

                return [
                    'id' => (int) $target->id,
                    'name' => (string) $target->name,
                    'is_central' => false,
                    'can_add' => (bool) ($grant?->can_add),
                    'can_view' => (bool) ($grant?->can_view),
                    'can_delete' => (bool) ($grant?->can_delete),
                    'can_archive' => (bool) ($grant?->can_archive),
                ];
            })->values()->all();
    }

    /**
     * @param  array{can_add?: bool, can_view?: bool, can_delete?: bool, can_archive?: bool}  $rights
     */
    public function setDocumentFirmRights(
        User $actor,
        Firm $firm,
        FirmDocument $document,
        Firm $granteeFirm,
        array $rights,
        ?Request $request = null,
        ?Hub $hub = null,
    ): FirmDocumentFirmRight {
        if ((int) $document->firm_id !== (int) $firm->id) {
            throw ValidationException::withMessages([
                'document_id' => 'Document does not belong to this firm.',
            ]);
        }

        if (! $firm->isCentral()) {
            throw ValidationException::withMessages([
                'firm_id' => 'Firm-level access applies only to Central / Network documents.',
            ]);
        }

        if (! $this->access->canManageFirmAccess($actor, $firm, $hub)) {
            throw new HttpException(403, 'You do not have permission to manage firm access for Central / Network documents.');
        }

        if ($granteeFirm->isCentral() || (int) $granteeFirm->id === (int) $firm->id) {
            throw ValidationException::withMessages([
                'grantee_firm_id' => 'Choose a non-central firm to grant access.',
            ]);
        }

        $canAdd = (bool) ($rights['can_add'] ?? false);
        $canView = (bool) ($rights['can_view'] ?? false);
        $canDelete = (bool) ($rights['can_delete'] ?? false);
        $canArchive = (bool) ($rights['can_archive'] ?? false);

        $existing = FirmDocumentFirmRight::query()
            ->where('firm_document_id', $document->id)
            ->where('grantee_firm_id', $granteeFirm->id)
            ->first();

        if (! $canAdd && ! $canView && ! $canDelete && ! $canArchive) {
            if ($existing) {
                $existing->delete();
                $this->activityLogs->log([
                    'action' => 'firm.documents.firm_rights.revoke',
                    'description' => 'Revoked firm access for '.$granteeFirm->name.' on “'.$document->title.'”',
                    'user' => $actor,
                    'hub' => $this->hubs->current(),
                    'subject' => $granteeFirm,
                    'request' => $request,
                    'status_code' => 200,
                    'properties' => [
                        'firm_id' => $firm->id,
                        'firm_name' => $firm->name,
                        'document_id' => $document->id,
                        'document_title' => $document->title,
                        'grantee_firm_id' => $granteeFirm->id,
                        'grantee_firm_name' => $granteeFirm->name,
                    ],
                ]);
            }

            return new FirmDocumentFirmRight([
                'firm_document_id' => $document->id,
                'grantee_firm_id' => $granteeFirm->id,
                'can_add' => false,
                'can_view' => false,
                'can_delete' => false,
                'can_archive' => false,
            ]);
        }

        $row = FirmDocumentFirmRight::query()->updateOrCreate(
            [
                'firm_document_id' => $document->id,
                'grantee_firm_id' => $granteeFirm->id,
            ],
            [
                'can_add' => $canAdd,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
            ]
        );

        $this->activityLogs->log([
            'action' => 'firm.documents.firm_rights.grant',
            'description' => 'Updated firm access for '.$granteeFirm->name.' on “'.$document->title.'”',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject' => $row,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'document_id' => $document->id,
                'document_title' => $document->title,
                'grantee_firm_id' => $granteeFirm->id,
                'grantee_firm_name' => $granteeFirm->name,
                'can_add' => $canAdd,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
            ],
        ]);

        return $row->load('granteeFirm:id,name,is_central');
    }

    /**
     * @param  array{can_add?: bool, can_view?: bool, can_delete?: bool, can_archive?: bool}  $rights
     */
    public function setDocumentMemberRights(
        User $actor,
        Firm $firm,
        FirmDocument $document,
        User $member,
        array $rights,
        ?Request $request = null,
        ?Hub $hub = null,
    ): FirmDocumentMemberRight {
        if ($firm->isCentral()) {
            throw ValidationException::withMessages([
                'user_id' => 'Central / Network documents use firm-level access. Pass grantee_firm_id instead of user_id.',
            ]);
        }

        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS, $hub);

        if ((int) $document->firm_id !== (int) $firm->id) {
            throw ValidationException::withMessages([
                'document_id' => 'Document does not belong to this firm.',
            ]);
        }

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
            ->where('firm_document_id', $document->id)
            ->where('user_id', $member->id)
            ->first();

        if (! $canAdd && ! $canView && ! $canDelete && ! $canArchive) {
            if ($existing) {
                $existing->delete();
                $this->activityLogs->log([
                    'action' => 'firm.documents.member_rights.revoke',
                    'description' => 'Revoked document rights for '.$member->name.' on “'.$document->title.'”',
                    'user' => $actor,
                    'hub' => $this->hubs->current(),
                    'subject' => $member,
                    'request' => $request,
                    'status_code' => 200,
                    'properties' => [
                        'firm_id' => $firm->id,
                        'firm_name' => $firm->name,
                        'document_id' => $document->id,
                        'document_title' => $document->title,
                        'target_user_id' => $member->id,
                        'target_user_name' => $member->name,
                    ],
                ]);
            }

            return new FirmDocumentMemberRight([
                'firm_id' => $firm->id,
                'firm_document_id' => $document->id,
                'user_id' => $member->id,
                'can_add' => false,
                'can_view' => false,
                'can_delete' => false,
                'can_archive' => false,
            ]);
        }

        $row = FirmDocumentMemberRight::query()->updateOrCreate(
            [
                'firm_document_id' => $document->id,
                'user_id' => $member->id,
            ],
            [
                'firm_id' => $firm->id,
                'can_add' => $canAdd,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
            ]
        );

        // Firm-wide upload: when can_add is granted on any document, also allow firm uploads.
        if ($canAdd) {
            $uploadGrant = FirmDocumentMemberRight::query()
                ->where('firm_id', $firm->id)
                ->whereNull('firm_document_id')
                ->where('user_id', $member->id)
                ->first();
            if ($uploadGrant) {
                $uploadGrant->update([
                    'can_add' => true,
                    'can_view' => false,
                    'can_delete' => false,
                    'can_archive' => false,
                ]);
            } else {
                FirmDocumentMemberRight::query()->create([
                    'firm_id' => $firm->id,
                    'firm_document_id' => null,
                    'user_id' => $member->id,
                    'can_add' => true,
                    'can_view' => false,
                    'can_delete' => false,
                    'can_archive' => false,
                ]);
            }
        }

        $this->activityLogs->log([
            'action' => 'firm.documents.member_rights.grant',
            'description' => 'Updated document rights for '.$member->name.' on “'.$document->title.'”',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject' => $row,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'document_id' => $document->id,
                'document_title' => $document->title,
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
     * @deprecated Firm-wide rights panel — kept for API compatibility; prefer per-document.
     *
     * @return list<FirmDocumentMemberRight>
     */
    public function listMemberRights(User $actor, Firm $firm, ?Hub $hub = null): array
    {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS, $hub);

        return FirmDocumentMemberRight::query()
            ->with('user:id,name,email')
            ->where('firm_id', $firm->id)
            ->whereNull('firm_document_id')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Firm-wide upload grant (can_add only).
     *
     * @param  array{can_add?: bool, can_view?: bool, can_delete?: bool, can_archive?: bool}  $rights
     */
    public function setMemberRights(
        User $actor,
        Firm $firm,
        User $member,
        array $rights,
        ?Request $request = null,
        ?Hub $hub = null,
    ): FirmDocumentMemberRight {
        $this->assertCan($actor, $firm, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS, $hub);

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

        $existing = FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->whereNull('firm_document_id')
            ->where('user_id', $member->id)
            ->first();

        if (! $canAdd) {
            if ($existing) {
                $existing->delete();
                $this->activityLogs->log([
                    'action' => 'firm.documents.member_rights.revoke',
                    'description' => 'Revoked firm document upload rights for '.$member->name.' on “'.$firm->name.'”',
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

            return new FirmDocumentMemberRight([
                'firm_id' => $firm->id,
                'user_id' => $member->id,
                'can_add' => false,
                'can_view' => false,
                'can_delete' => false,
                'can_archive' => false,
            ]);
        }

        $row = FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->whereNull('firm_document_id')
            ->where('user_id', $member->id)
            ->first();
        if ($row) {
            $row->update([
                'can_add' => true,
                'can_view' => false,
                'can_delete' => false,
                'can_archive' => false,
            ]);
        } else {
            $row = FirmDocumentMemberRight::query()->create([
                'firm_id' => $firm->id,
                'firm_document_id' => null,
                'user_id' => $member->id,
                'can_add' => true,
                'can_view' => false,
                'can_delete' => false,
                'can_archive' => false,
            ]);
        }

        $this->activityLogs->log([
            'action' => 'firm.documents.member_rights.grant',
            'description' => 'Updated firm document upload rights for '.$member->name.' on “'.$firm->name.'”',
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
                'can_add' => true,
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
