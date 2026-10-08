<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use App\Support\ComplianceSupportingFiles;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Firm documents on a remote Shared / White-label hub DB while hub switcher is active.
 */
class WhiteLabelFirmDocumentService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly WhiteLabelFirmService $whiteLabelFirms,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs,
    ) {}

    public function assertTarget(Hub $hub): void
    {
        $this->whiteLabelFirms->assertTarget($hub);
    }

    /**
     * Nested folder library for a remote hub (same shape as local FirmDocumentService::library).
     *
     * @return array<string, mixed>
     */
    public function index(Hub $hub, User $actor, int $firmId, string $scope, int $perPage = 25, int $page = 1): array
    {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);
        $this->assertFirmExists($hub, $firmId);
        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_VIEW);

        $rights = $this->rightsPayload($hub, $actor, $firmId);
        $viewerRights = [
            'can_view' => (bool) ($rights['can_view'] ?? false),
            'can_delete' => (bool) ($rights['can_delete'] ?? false),
            'can_archive' => (bool) ($rights['can_archive'] ?? false),
            'can_manage_member_rights' => (bool) ($rights['can_manage_member_rights'] ?? false),
            'access_mode' => 'mixed',
        ];

        $payload = $this->remoteDb->run($hub, function (string $connection) use ($firmId, $scope, $viewerRights) {
            $this->assertDocumentsTables($connection);
            $schema = DB::connection($connection)->getSchemaBuilder();
            $hasFolders = $schema->hasTable('firm_document_folders');
            $hasFolderCol = $schema->hasColumn('firm_documents', 'folder_id');
            $hasCategoryCol = $schema->hasColumn('firm_documents', 'category_id');
            $hasFirmRights = $schema->hasTable('firm_document_firm_rights');

            $query = DB::connection($connection)->table('firm_documents')
                ->where('firm_id', $firmId)
                ->orderBy('title')
                ->orderBy('id');

            if ($scope === 'archived') {
                $query->whereNotNull('archived_at');
            } elseif ($scope !== 'all') {
                $query->whereNull('archived_at');
            }

            $rows = $query->get();
            $serialized = $rows->map(function ($row) use ($connection, $viewerRights, $hasFolders, $hasFolderCol, $hasCategoryCol) {
                return $this->serializeDocument(
                    $connection,
                    $row,
                    $viewerRights,
                    $hasFolders && $hasFolderCol,
                    $hasCategoryCol,
                );
            })->values();

            $folders = collect();
            if ($hasFolders) {
                $folders = DB::connection($connection)->table('firm_document_folders')
                    ->where('firm_id', $firmId)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get();
            }

            $byParent = $folders->groupBy(fn ($f) => $f->parent_id === null ? 'root' : (string) $f->parent_id);
            $docsByFolder = $serialized->groupBy(function (array $d) {
                return $d['folder_id'] === null ? 'none' : (string) $d['folder_id'];
            });

            $buildFolder = function ($folder) use (&$buildFolder, $byParent, $docsByFolder): array {
                $childFolders = ($byParent->get((string) $folder->id) ?? collect())
                    ->map(fn ($child) => $buildFolder($child))
                    ->values()
                    ->all();

                $folderDocs = ($docsByFolder->get((string) $folder->id) ?? collect())->values()->all();

                return [
                    'id' => (int) $folder->id,
                    'firm_id' => (int) $folder->firm_id,
                    'parent_id' => $folder->parent_id ? (int) $folder->parent_id : null,
                    'name' => (string) $folder->name,
                    'created_by' => isset($folder->created_by) && $folder->created_by
                        ? (int) $folder->created_by
                        : null,
                    'created_at' => $folder->created_at ?? null,
                    'updated_at' => $folder->updated_at ?? null,
                    'children' => $childFolders,
                    'documents' => $folderDocs,
                    'document_count' => count($folderDocs) + collect($childFolders)->sum('document_count'),
                ];
            };

            $tree = ($byParent->get('root') ?? collect())
                ->map(fn ($folder) => $buildFolder($folder))
                ->values()
                ->all();

            $unfiled = ($docsByFolder->get('none') ?? collect())->values()->all();

            // Shared docs are opened via the owner firm in the picker (not mixed here).

            return [
                'firm' => $this->serializeFirm($connection, $firmId),
                'folders' => $tree,
                'documents' => $serialized->values()->all(),
                'unfiled_documents' => $unfiled,
            ];
        });

        return array_merge($payload, ['rights' => $rights]);
    }

    /**
     * Flat folder list for upload / “new folder” pickers (remote hub).
     *
     * @return list<array<string, mixed>>
     */
    public function listFolders(Hub $hub, User $actor, int $firmId): array
    {
        $this->assertTarget($hub);
        $this->assertFirmExists($hub, $firmId);
        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_VIEW);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId) {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('firm_document_folders')) {
                return [];
            }

            return DB::connection($connection)->table('firm_document_folders')
                ->where('firm_id', $firmId)
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn ($folder) => [
                    'id' => (int) $folder->id,
                    'firm_id' => (int) $folder->firm_id,
                    'parent_id' => $folder->parent_id ? (int) $folder->parent_id : null,
                    'name' => (string) $folder->name,
                    'created_by' => isset($folder->created_by) && $folder->created_by
                        ? (int) $folder->created_by
                        : null,
                    'created_at' => $folder->created_at ?? null,
                    'updated_at' => $folder->updated_at ?? null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function createFolder(
        Hub $hub,
        User $actor,
        int $firmId,
        string $name,
        ?int $parentId = null,
        ?Request $request = null,
    ): array {
        $this->assertTarget($hub);
        $this->assertFirmExists($hub, $firmId);
        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_ADD);

        $remoteUserId = $this->resolveRemoteActorId($hub, $actor);

        $folder = $this->remoteDb->run($hub, function (string $connection) use ($firmId, $name, $parentId, $remoteUserId) {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('firm_document_folders')) {
                throw new InvalidArgumentException(
                    'This hub has not been migrated for Firm Document folders yet. Run migrations on that hub’s database.'
                );
            }

            if ($parentId !== null) {
                $parent = DB::connection($connection)->table('firm_document_folders')
                    ->where('firm_id', $firmId)
                    ->where('id', $parentId)
                    ->first();
                if (! $parent) {
                    throw new InvalidArgumentException('Parent folder not found for this firm.');
                }
            }

            $id = (int) DB::connection($connection)->table('firm_document_folders')->insertGetId([
                'firm_id' => $firmId,
                'parent_id' => $parentId,
                'name' => $name,
                'created_by' => $remoteUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::connection($connection)->table('firm_document_folders')->where('id', $id)->first();

            return [
                'id' => (int) $row->id,
                'firm_id' => (int) $row->firm_id,
                'parent_id' => $row->parent_id ? (int) $row->parent_id : null,
                'name' => (string) $row->name,
                'created_by' => $row->created_by ? (int) $row->created_by : null,
                'created_at' => $row->created_at ?? null,
                'updated_at' => $row->updated_at ?? null,
            ];
        });

        $this->activityLogs->log([
            'action' => 'firm.documents.folder.create',
            'description' => 'Created firm document folder “'.$folder['name'].'” (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'firm_id' => $firmId,
                'folder_id' => $folder['id'],
                'folder_name' => $folder['name'],
                'parent_id' => $parentId,
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ],
        ]);

        return $folder;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  array{folder_id?: mixed, folder_name?: mixed, parent_folder_id?: mixed, category_id?: mixed}  $meta
     * @return array<string, mixed>
     */
    public function create(
        Hub $hub,
        User $actor,
        int $firmId,
        string $title,
        ?string $description,
        array $files,
        ?Request $request = null,
        array $meta = [],
    ): array {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);
        $this->assertFirmExists($hub, $firmId);
        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_ADD);

        if ($files === []) {
            throw new InvalidArgumentException('At least one attachment is required.');
        }
        ComplianceSupportingFiles::assertWithinLimits($files, 'attachments');

        $remoteUserId = $this->resolveRemoteActorId($hub, $actor);

        $document = $this->remoteDb->run($hub, function (string $connection) use ($firmId, $title, $description, $files, $remoteUserId, $meta) {
            $this->assertDocumentsTables($connection);
            $schema = DB::connection($connection)->getSchemaBuilder();
            $hasFolderCol = $schema->hasColumn('firm_documents', 'folder_id');
            $hasCategoryCol = $schema->hasColumn('firm_documents', 'category_id');
            $hasFolders = $schema->hasTable('firm_document_folders');

            $folderId = null;
            if ($hasFolders && $hasFolderCol) {
                $folderId = $this->resolveFolderIdOn($connection, $firmId, $meta, $remoteUserId);
            }

            $categoryId = null;
            if ($hasCategoryCol && ! empty($meta['category_id'])) {
                $categoryId = (int) $meta['category_id'];
                if ($schema->hasTable('firm_document_categories')
                    && ! DB::connection($connection)->table('firm_document_categories')->where('id', $categoryId)->exists()
                ) {
                    throw new InvalidArgumentException('Select a valid category.');
                }
            }

            $payload = [
                'firm_id' => $firmId,
                'title' => $title,
                'description' => $description,
                'uploaded_by' => $remoteUserId,
                'archived_at' => null,
                'archived_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($hasFolderCol) {
                $payload['folder_id'] = $folderId;
            }
            if ($hasCategoryCol) {
                $payload['category_id'] = $categoryId;
            }

            $docId = (int) DB::connection($connection)->table('firm_documents')->insertGetId($payload);

            foreach (array_values($files) as $index => $file) {
                $path = $file->store('firm-documents/'.$firmId, 'public');
                DB::connection($connection)->table('firm_document_attachments')->insert([
                    'firm_document_id' => $docId,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_url' => Storage::disk('public')->url($path),
                    'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
                    'size_bytes' => $file->getSize() ?: null,
                    'sort_order' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $row = DB::connection($connection)->table('firm_documents')->where('id', $docId)->first();

            return $this->serializeDocument($connection, $row, null, $hasFolders && $hasFolderCol, $hasCategoryCol);
        });

        $this->activityLogs->log([
            'action' => 'firm.documents.add',
            'description' => 'Added firm document “'.$title.'” (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'firm_id' => $firmId,
                'document_id' => $document['id'] ?? null,
                'document_title' => $title,
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ],
        ]);

        return $document;
    }

    /**
     * @return array{firm: array<string, mixed>, document: array<string, mixed>}
     */
    public function show(Hub $hub, User $actor, int $documentId): array
    {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);

        $meta = $this->remoteDb->run($hub, function (string $connection) use ($documentId) {
            $this->assertDocumentsTables($connection);
            $row = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Document not found on this hub.');
            }

            return ['firm_id' => (int) $row->firm_id];
        });

        $this->assertCanOnRemote($hub, $actor, $meta['firm_id'], FirmDocumentAccessService::RIGHT_VIEW);

        $rights = $this->rightsPayload($hub, $actor, $meta['firm_id']);
        $viewerRights = [
            'can_view' => (bool) ($rights['can_view'] ?? false),
            'can_delete' => (bool) ($rights['can_delete'] ?? false),
            'can_archive' => (bool) ($rights['can_archive'] ?? false),
            'can_manage_member_rights' => (bool) ($rights['can_manage_member_rights'] ?? false),
            'access_mode' => 'mixed',
        ];

        return $this->remoteDb->run($hub, function (string $connection) use ($documentId, $viewerRights, $meta) {
            $this->assertDocumentsTables($connection);
            $schema = DB::connection($connection)->getSchemaBuilder();
            $row = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Document not found on this hub.');
            }

            return [
                'firm' => $this->serializeFirm($connection, $meta['firm_id']),
                'document' => $this->serializeDocument(
                    $connection,
                    $row,
                    $viewerRights,
                    $schema->hasTable('firm_document_folders') && $schema->hasColumn('firm_documents', 'folder_id'),
                    $schema->hasColumn('firm_documents', 'category_id'),
                ),
            ];
        });
    }

    public function delete(Hub $hub, User $actor, int $documentId, ?Request $request = null): void
    {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);

        $meta = $this->remoteDb->run($hub, function (string $connection) use ($documentId) {
            $this->assertDocumentsTables($connection);
            $row = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Document not found on this hub.');
            }

            return [
                'firm_id' => (int) $row->firm_id,
                'title' => (string) $row->title,
            ];
        });

        $this->assertCanOnRemote($hub, $actor, $meta['firm_id'], FirmDocumentAccessService::RIGHT_DELETE);

        $this->remoteDb->run($hub, function (string $connection) use ($documentId) {
            $attachments = DB::connection($connection)->table('firm_document_attachments')
                ->where('firm_document_id', $documentId)
                ->get();

            foreach ($attachments as $attachment) {
                if (! empty($attachment->file_path)
                    && ! str_starts_with((string) $attachment->file_path, 'http://')
                    && ! str_starts_with((string) $attachment->file_path, 'https://')
                ) {
                    try {
                        Storage::disk('public')->delete($attachment->file_path);
                    } catch (\Throwable) {
                    }
                }
            }

            DB::connection($connection)->table('firm_document_attachments')->where('firm_document_id', $documentId)->delete();
            DB::connection($connection)->table('firm_documents')->where('id', $documentId)->delete();
        });

        $this->activityLogs->log([
            'action' => 'firm.documents.delete',
            'description' => 'Deleted firm document “'.$meta['title'].'” (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $meta['firm_id'],
                'document_id' => $documentId,
                'document_title' => $meta['title'],
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(Hub $hub, User $actor, int $documentId, bool $archive = true, ?Request $request = null): array
    {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);

        $firmId = $this->remoteDb->run($hub, function (string $connection) use ($documentId) {
            $this->assertDocumentsTables($connection);
            $row = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Document not found on this hub.');
            }

            return (int) $row->firm_id;
        });

        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_ARCHIVE);
        $remoteUserId = $this->resolveRemoteActorId($hub, $actor);

        $document = $this->remoteDb->run($hub, function (string $connection) use ($documentId, $archive, $remoteUserId) {
            if ($archive) {
                DB::connection($connection)->table('firm_documents')->where('id', $documentId)->update([
                    'archived_at' => now(),
                    'archived_by' => $remoteUserId,
                    'updated_at' => now(),
                ]);
            } else {
                DB::connection($connection)->table('firm_documents')->where('id', $documentId)->update([
                    'archived_at' => null,
                    'archived_by' => null,
                    'updated_at' => now(),
                ]);
            }

            $fresh = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();

            return $this->serializeDocument($connection, $fresh);
        });

        $this->activityLogs->log([
            'action' => $archive ? 'firm.documents.archive' : 'firm.documents.unarchive',
            'description' => ($archive ? 'Archived' : 'Unarchived').' firm document “'.($document['title'] ?? '').'” (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $document['firm_id'] ?? null,
                'document_id' => $documentId,
                'document_title' => $document['title'] ?? null,
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ],
        ]);

        return $document;
    }

    /**
     * Key-icon access rights payload for a remote document.
     *
     * @return array{
     *   firm: array<string, mixed>,
     *   document: array{id: int, title: string, is_central: bool},
     *   mode: string,
     *   members: list<array<string, mixed>>,
     *   firms: list<array<string, mixed>>
     * }
     */
    public function listDocumentAccessRights(Hub $hub, User $actor, int $documentId): array
    {
        $this->assertTarget($hub);

        $meta = $this->remoteDb->run($hub, function (string $connection) use ($documentId) {
            $this->assertDocumentsTables($connection);
            $row = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Document not found on this hub.');
            }

            return [
                'firm_id' => (int) $row->firm_id,
                'title' => (string) $row->title,
            ];
        });

        $this->assertCanManageDocumentAccessRights($hub, $actor, $meta['firm_id']);

        return $this->remoteDb->run($hub, function (string $connection) use ($documentId, $meta) {
            $firm = $this->serializeFirm($connection, $meta['firm_id']);
            $headId = isset($firm['head_user_id']) ? (int) $firm['head_user_id'] : null;
            $schema = DB::connection($connection)->getSchemaBuilder();

            $grants = collect();
            if ($schema->hasTable('firm_document_member_rights')) {
                $grants = DB::connection($connection)->table('firm_document_member_rights')
                    ->where('firm_document_id', $documentId)
                    ->get()
                    ->keyBy('user_id');
            }

            $memberQuery = DB::connection($connection)->table('users')
                ->where(function ($q) use ($meta, $headId) {
                    $q->where('firm_id', $meta['firm_id']);
                    if ($headId) {
                        $q->orWhere('id', $headId);
                    }
                })
                ->orderBy('name');

            if ($schema->hasColumn('users', 'is_discontinued')) {
                $memberQuery->where(function ($q) {
                    $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
                });
            }

            $members = $memberQuery->get(['id', 'name', 'email'])->map(function ($member) use ($grants, $headId) {
                $isHead = $headId && (int) $headId === (int) $member->id;
                $grant = $grants->get($member->id);

                return [
                    'id' => (int) $member->id,
                    'name' => (string) $member->name,
                    'email' => (string) $member->email,
                    'is_firm_head' => $isHead,
                    'can_add' => $isHead,
                    'can_view' => $isHead ? true : (bool) ($grant->can_view ?? false),
                    'can_delete' => $isHead ? true : (bool) ($grant->can_delete ?? false),
                    'can_archive' => $isHead ? true : (bool) ($grant->can_archive ?? false),
                ];
            })->values()->all();

            $firms = [];
            if ($schema->hasTable('firm_document_visible_firms')) {
                $allowedIds = $this->visibleGranteeFirmIdsOn($connection, $meta['firm_id']);
                $firmGrants = collect();
                if ($schema->hasTable('firm_document_firm_rights') && $allowedIds !== []) {
                    $firmGrants = DB::connection($connection)->table('firm_document_firm_rights')
                        ->where('firm_document_id', $documentId)
                        ->whereIn('grantee_firm_id', $allowedIds)
                        ->get()
                        ->keyBy('grantee_firm_id');
                }

                if ($allowedIds !== []) {
                    $firms = DB::connection($connection)->table('firms')
                        ->whereIn('id', $allowedIds)
                        ->where('id', '!=', $meta['firm_id'])
                        ->orderBy('name')
                        ->orderBy('id')
                        ->get(['id', 'name', 'is_central'])
                        ->map(function ($target) use ($firmGrants) {
                            $grant = $firmGrants->get($target->id);

                            return [
                                'id' => (int) $target->id,
                                'name' => (string) $target->name,
                                'is_central' => (bool) ($target->is_central ?? false),
                                'can_add' => false,
                                'can_view' => (bool) ($grant->can_view ?? false),
                                'can_delete' => (bool) ($grant->can_delete ?? false),
                                'can_archive' => (bool) ($grant->can_archive ?? false),
                            ];
                        })
                        ->values()
                        ->all();
                }
            }

            return [
                'firm' => $firm,
                'document' => [
                    'id' => $documentId,
                    'title' => $meta['title'],
                    'is_central' => (bool) ($firm['is_central'] ?? false),
                ],
                'mode' => 'mixed',
                'members' => $members,
                'firms' => $firms,
            ];
        });
    }

    /**
     * @param  array{can_add?: bool, can_view?: bool, can_delete?: bool, can_archive?: bool}  $rights
     * @return array<string, mixed>
     */
    public function setDocumentMemberRights(
        Hub $hub,
        User $actor,
        int $documentId,
        int $memberUserId,
        array $rights,
        ?Request $request = null,
    ): array {
        $this->assertTarget($hub);

        $meta = $this->remoteDb->run($hub, function (string $connection) use ($documentId) {
            $this->assertDocumentsTables($connection);
            $row = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Document not found on this hub.');
            }

            return [
                'firm_id' => (int) $row->firm_id,
                'title' => (string) $row->title,
            ];
        });

        $this->assertCanManageDocumentAccessRights($hub, $actor, $meta['firm_id']);

        $result = $this->remoteDb->run($hub, function (string $connection) use ($documentId, $memberUserId, $rights, $meta) {
            $this->assertDocumentsTables($connection);
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('firm_document_member_rights')) {
                throw new InvalidArgumentException('Document access rights are not available on this hub yet.');
            }

            $member = DB::connection($connection)->table('users')->where('id', $memberUserId)->first();
            if (! $member) {
                throw new InvalidArgumentException('User not found on this hub.');
            }

            $firm = DB::connection($connection)->table('firms')->where('id', $meta['firm_id'])->first();
            $headId = $firm->head_user_id ?? null;
            if ($headId && (int) $headId === $memberUserId) {
                throw new InvalidArgumentException('The Head of Firm already has all document rights.');
            }
            if ((int) ($member->firm_id ?? 0) !== (int) $meta['firm_id']) {
                throw new InvalidArgumentException('Document rights can only be granted to members of this firm.');
            }

            $canView = (bool) ($rights['can_view'] ?? false);
            $canDelete = (bool) ($rights['can_delete'] ?? false);
            $canArchive = (bool) ($rights['can_archive'] ?? false);

            $existing = DB::connection($connection)->table('firm_document_member_rights')
                ->where('firm_document_id', $documentId)
                ->where('user_id', $memberUserId)
                ->first();

            if (! $canView && ! $canDelete && ! $canArchive) {
                if ($existing) {
                    DB::connection($connection)->table('firm_document_member_rights')
                        ->where('id', $existing->id)
                        ->delete();
                }

                return [
                    'revoked' => true,
                    'rights' => [
                        'firm_id' => $meta['firm_id'],
                        'firm_document_id' => $documentId,
                        'user_id' => $memberUserId,
                        'can_add' => false,
                        'can_view' => false,
                        'can_delete' => false,
                        'can_archive' => false,
                        'user' => [
                            'id' => (int) $member->id,
                            'name' => (string) $member->name,
                            'email' => (string) $member->email,
                        ],
                    ],
                ];
            }

            $payload = [
                'firm_id' => $meta['firm_id'],
                'firm_document_id' => $documentId,
                'user_id' => $memberUserId,
                'can_add' => false,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::connection($connection)->table('firm_document_member_rights')
                    ->where('id', $existing->id)
                    ->update($payload);
            } else {
                $payload['created_at'] = now();
                DB::connection($connection)->table('firm_document_member_rights')->insert($payload);
            }

            return [
                'revoked' => false,
                'rights' => [
                    'firm_id' => $meta['firm_id'],
                    'firm_document_id' => $documentId,
                    'user_id' => $memberUserId,
                    'can_add' => false,
                    'can_view' => $canView,
                    'can_delete' => $canDelete,
                    'can_archive' => $canArchive,
                    'user' => [
                        'id' => (int) $member->id,
                        'name' => (string) $member->name,
                        'email' => (string) $member->email,
                    ],
                ],
            ];
        });

        $this->activityLogs->log([
            'action' => ($result['revoked'] ?? false)
                ? 'firm.documents.member_rights.revoke'
                : 'firm.documents.member_rights.grant',
            'description' => (($result['revoked'] ?? false) ? 'Revoked' : 'Updated')
                .' document access for '.($result['rights']['user']['name'] ?? 'user').' (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => array_merge($result['rights'] ?? [], [
                'document_title' => $meta['title'],
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ]),
        ]);

        return $result['rights'];
    }

    /**
     * @param  array{can_add?: bool, can_view?: bool, can_delete?: bool, can_archive?: bool}  $rights
     * @return array<string, mixed>
     */
    public function setDocumentFirmRights(
        Hub $hub,
        User $actor,
        int $documentId,
        int $granteeFirmId,
        array $rights,
        ?Request $request = null,
    ): array {
        $this->assertTarget($hub);

        $meta = $this->remoteDb->run($hub, function (string $connection) use ($documentId) {
            $this->assertDocumentsTables($connection);
            $row = DB::connection($connection)->table('firm_documents')->where('id', $documentId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Document not found on this hub.');
            }

            return [
                'firm_id' => (int) $row->firm_id,
                'title' => (string) $row->title,
            ];
        });

        $this->assertCanManageDocumentAccessRights($hub, $actor, $meta['firm_id']);

        if ($granteeFirmId === (int) $meta['firm_id']) {
            throw new InvalidArgumentException('Choose a different firm to grant access.');
        }

        $result = $this->remoteDb->run($hub, function (string $connection) use ($documentId, $granteeFirmId, $rights, $meta) {
            $this->assertDocumentsTables($connection);
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('firm_document_firm_rights')) {
                throw new InvalidArgumentException('Firm access rights are not available on this hub yet.');
            }

            $grantee = DB::connection($connection)->table('firms')->where('id', $granteeFirmId)->first(['id', 'name']);
            if (! $grantee) {
                throw new InvalidArgumentException('Firm not found on this hub.');
            }

            $allowed = $this->visibleGranteeFirmIdsOn($connection, $meta['firm_id']);
            if (! in_array($granteeFirmId, $allowed, true)) {
                throw new InvalidArgumentException(
                    'That firm is not on the allowlist. Add it under “which firms can see documents” first.'
                );
            }

            $canView = (bool) ($rights['can_view'] ?? false);
            $canDelete = (bool) ($rights['can_delete'] ?? false);
            $canArchive = (bool) ($rights['can_archive'] ?? false);

            $existing = DB::connection($connection)->table('firm_document_firm_rights')
                ->where('firm_document_id', $documentId)
                ->where('grantee_firm_id', $granteeFirmId)
                ->first();

            if (! $canView && ! $canDelete && ! $canArchive) {
                if ($existing) {
                    DB::connection($connection)->table('firm_document_firm_rights')
                        ->where('id', $existing->id)
                        ->delete();
                }

                return [
                    'revoked' => true,
                    'rights' => [
                        'firm_document_id' => $documentId,
                        'grantee_firm_id' => $granteeFirmId,
                        'can_add' => false,
                        'can_view' => false,
                        'can_delete' => false,
                        'can_archive' => false,
                        'firm' => [
                            'id' => (int) $grantee->id,
                            'name' => (string) $grantee->name,
                        ],
                    ],
                ];
            }

            $payload = [
                'firm_document_id' => $documentId,
                'grantee_firm_id' => $granteeFirmId,
                'can_add' => false,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::connection($connection)->table('firm_document_firm_rights')
                    ->where('id', $existing->id)
                    ->update($payload);
            } else {
                $payload['created_at'] = now();
                DB::connection($connection)->table('firm_document_firm_rights')->insert($payload);
            }

            return [
                'revoked' => false,
                'rights' => [
                    'firm_document_id' => $documentId,
                    'grantee_firm_id' => $granteeFirmId,
                    'can_add' => false,
                    'can_view' => $canView,
                    'can_delete' => $canDelete,
                    'can_archive' => $canArchive,
                    'firm' => [
                        'id' => (int) $grantee->id,
                        'name' => (string) $grantee->name,
                    ],
                ],
            ];
        });

        $this->activityLogs->log([
            'action' => ($result['revoked'] ?? false)
                ? 'firm.documents.firm_rights.revoke'
                : 'firm.documents.firm_rights.grant',
            'description' => (($result['revoked'] ?? false) ? 'Revoked' : 'Updated')
                .' firm access for '.($result['rights']['firm']['name'] ?? 'firm').' (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => array_merge($result['rights'] ?? [], [
                'document_title' => $meta['title'],
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ]),
        ]);

        return $result['rights'];
    }

    /**
     * Firms allowed to appear in this firm’s document access rights (remote hub).
     *
     * @return array{
     *   owner_firm: array<string, mixed>,
     *   selected_firm_ids: list<int>,
     *   firms: list<array{id: int, name: string, selected: bool}>
     * }
     */
    public function listVisibleFirms(Hub $hub, User $actor, int $ownerFirmId): array
    {
        $this->assertTarget($hub);
        $this->assertFirmExists($hub, $ownerFirmId);
        $this->assertCanManageFirmAccess($hub, $actor, $ownerFirmId);

        return $this->remoteDb->run($hub, function (string $connection) use ($ownerFirmId) {
            $ownerFirm = $this->serializeFirm($connection, $ownerFirmId);
            $selectedIds = $this->visibleGranteeFirmIdsOn($connection, $ownerFirmId);

            $firms = DB::connection($connection)->table('firms')
                ->where('id', '!=', $ownerFirmId)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'selected' => in_array((int) $row->id, $selectedIds, true),
                ])
                ->values()
                ->all();

            return [
                'owner_firm' => $ownerFirm,
                'selected_firm_ids' => $selectedIds,
                'firms' => $firms,
            ];
        });
    }

    /**
     * Replace the allowlist of firms that may see this firm’s documents (remote hub).
     *
     * @param  list<int>  $firmIds
     * @return array{
     *   owner_firm: array<string, mixed>,
     *   selected_firm_ids: list<int>,
     *   firms: list<array{id: int, name: string, selected: bool}>
     * }
     */
    public function syncVisibleFirms(
        Hub $hub,
        User $actor,
        int $ownerFirmId,
        array $firmIds,
        ?Request $request = null,
    ): array {
        $this->assertTarget($hub);
        $this->assertFirmExists($hub, $ownerFirmId);
        $this->assertCanManageFirmAccess($hub, $actor, $ownerFirmId);

        $result = $this->remoteDb->run($hub, function (string $connection) use ($ownerFirmId, $firmIds) {
            $this->assertVisibleFirmsTable($connection);

            $normalized = collect($firmIds)
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $id === $ownerFirmId)
                ->unique()
                ->values()
                ->all();

            if ($normalized !== []) {
                $valid = DB::connection($connection)->table('firms')
                    ->whereIn('id', $normalized)
                    ->where('id', '!=', $ownerFirmId)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if (count($valid) !== count($normalized)) {
                    throw new InvalidArgumentException('Choose existing firms other than your own.');
                }
                $normalized = $valid;
            }

            $previous = $this->visibleGranteeFirmIdsOn($connection, $ownerFirmId);
            $removed = array_values(array_diff($previous, $normalized));

            DB::connection($connection)->table('firm_document_visible_firms')
                ->where('owner_firm_id', $ownerFirmId)
                ->delete();

            $now = now();
            foreach ($normalized as $granteeId) {
                DB::connection($connection)->table('firm_document_visible_firms')->insert([
                    'owner_firm_id' => $ownerFirmId,
                    'grantee_firm_id' => $granteeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Drop per-document firm grants for firms no longer on the allowlist.
            if ($removed !== [] && DB::connection($connection)->getSchemaBuilder()->hasTable('firm_document_firm_rights')) {
                $documentIds = DB::connection($connection)->table('firm_documents')
                    ->where('firm_id', $ownerFirmId)
                    ->pluck('id')
                    ->all();

                if ($documentIds !== []) {
                    DB::connection($connection)->table('firm_document_firm_rights')
                        ->whereIn('grantee_firm_id', $removed)
                        ->whereIn('firm_document_id', $documentIds)
                        ->delete();
                }
            }

            $ownerFirm = $this->serializeFirm($connection, $ownerFirmId);
            $firms = DB::connection($connection)->table('firms')
                ->where('id', '!=', $ownerFirmId)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'selected' => in_array((int) $row->id, $normalized, true),
                ])
                ->values()
                ->all();

            return [
                'owner_firm' => $ownerFirm,
                'selected_firm_ids' => $normalized,
                'firms' => $firms,
                'removed_firm_ids' => $removed,
            ];
        });

        $this->activityLogs->log([
            'action' => 'firm.documents.visible_firms.sync',
            'description' => 'Updated which firms may see documents for “'
                .($result['owner_firm']['name'] ?? 'firm').'” (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $ownerFirmId,
                'firm_name' => $result['owner_firm']['name'] ?? null,
                'selected_firm_ids' => $result['selected_firm_ids'],
                'removed_firm_ids' => $result['removed_firm_ids'] ?? [],
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ],
        ]);

        unset($result['removed_firm_ids']);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function memberRights(Hub $hub, User $actor, int $firmId): array
    {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);
        $this->assertFirmExists($hub, $firmId);
        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId) {
            $this->assertDocumentsTables($connection);

            $firm = $this->serializeFirm($connection, $firmId);
            $headId = $firm['head_user_id'] ?? null;

            $grants = DB::connection($connection)->table('firm_document_member_rights')
                ->where('firm_id', $firmId)
                ->get()
                ->keyBy('user_id');

            $query = DB::connection($connection)->table('users')
                ->where('firm_id', $firmId)
                ->orderBy('name');

            if (DB::connection($connection)->getSchemaBuilder()->hasColumn('users', 'is_discontinued')) {
                $query->where(function ($q) {
                    $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
                });
            }

            $members = $query->get(['id', 'name', 'email'])->map(function ($member) use ($grants, $headId) {
                $isHead = $headId && (int) $headId === (int) $member->id;
                $grant = $grants->get($member->id);

                return [
                    'id' => (int) $member->id,
                    'name' => (string) $member->name,
                    'email' => (string) $member->email,
                    'is_firm_head' => $isHead,
                    'can_add' => $isHead ? true : (bool) ($grant->can_add ?? false),
                    'can_view' => $isHead ? true : (bool) ($grant->can_view ?? false),
                    'can_delete' => $isHead ? true : (bool) ($grant->can_delete ?? false),
                    'can_archive' => $isHead ? true : (bool) ($grant->can_archive ?? false),
                ];
            })->values()->all();

            return [
                'firm' => $firm,
                'members' => $members,
            ];
        });
    }

    /**
     * @param  array{can_add?: bool, can_view?: bool, can_delete?: bool, can_archive?: bool}  $rights
     * @return array<string, mixed>
     */
    public function setMemberRights(Hub $hub, User $actor, int $firmId, int $memberUserId, array $rights, ?Request $request = null): array
    {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);
        $this->assertFirmExists($hub, $firmId);
        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS);

        $result = $this->remoteDb->run($hub, function (string $connection) use ($firmId, $memberUserId, $rights) {
            $this->assertDocumentsTables($connection);
            $member = DB::connection($connection)->table('users')->where('id', $memberUserId)->first();
            if (! $member) {
                throw new InvalidArgumentException('User not found on this hub.');
            }
            if ((int) ($member->firm_id ?? 0) !== $firmId) {
                throw new InvalidArgumentException('Document rights can only be granted to members of this firm.');
            }

            $firm = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
            if ($firm && (int) ($firm->head_user_id ?? 0) === $memberUserId) {
                throw new InvalidArgumentException('The Head of Firm already has all document rights.');
            }

            $canAdd = (bool) ($rights['can_add'] ?? false);
            $canView = (bool) ($rights['can_view'] ?? false);
            $canDelete = (bool) ($rights['can_delete'] ?? false);
            $canArchive = (bool) ($rights['can_archive'] ?? false);

            $existing = DB::connection($connection)->table('firm_document_member_rights')
                ->where('firm_id', $firmId)
                ->where('user_id', $memberUserId)
                ->first();

            if (! $canAdd && ! $canView && ! $canDelete && ! $canArchive) {
                if ($existing) {
                    DB::connection($connection)->table('firm_document_member_rights')
                        ->where('id', $existing->id)
                        ->delete();
                }

                return [
                    'revoked' => true,
                    'member' => [
                        'id' => (int) $member->id,
                        'name' => (string) $member->name,
                        'email' => (string) $member->email,
                    ],
                    'rights' => [
                        'firm_id' => $firmId,
                        'user_id' => $memberUserId,
                        'can_add' => false,
                        'can_view' => false,
                        'can_delete' => false,
                        'can_archive' => false,
                    ],
                ];
            }

            $payload = [
                'can_add' => $canAdd,
                'can_view' => $canView,
                'can_delete' => $canDelete,
                'can_archive' => $canArchive,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::connection($connection)->table('firm_document_member_rights')
                    ->where('id', $existing->id)
                    ->update($payload);
            } else {
                DB::connection($connection)->table('firm_document_member_rights')->insert(array_merge($payload, [
                    'firm_id' => $firmId,
                    'user_id' => $memberUserId,
                    'created_at' => now(),
                ]));
            }

            return [
                'revoked' => false,
                'member' => [
                    'id' => (int) $member->id,
                    'name' => (string) $member->name,
                    'email' => (string) $member->email,
                ],
                'rights' => [
                    'firm_id' => $firmId,
                    'user_id' => $memberUserId,
                    'can_add' => $canAdd,
                    'can_view' => $canView,
                    'can_delete' => $canDelete,
                    'can_archive' => $canArchive,
                    'user' => [
                        'id' => (int) $member->id,
                        'name' => (string) $member->name,
                        'email' => (string) $member->email,
                    ],
                ],
            ];
        });

        $this->activityLogs->log([
            'action' => ($result['revoked'] ?? false)
                ? 'firm.documents.member_rights.revoke'
                : 'firm.documents.member_rights.grant',
            'description' => (($result['revoked'] ?? false) ? 'Revoked' : 'Updated')
                .' firm document rights for '.($result['member']['name'] ?? 'user').' (remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => array_merge($result['rights'] ?? [], [
                'acting_remotely' => true,
                'target_hub_id' => $hub->id,
            ]),
        ]);

        return $result['rights'];
    }

    /**
     * @return array<string, bool|null>
     */
    public function rightsPayload(Hub $hub, User $actor, int $firmId): array
    {
        $canManageFirmAccess = $this->actorCanManageFirmAccess($hub, $actor, $firmId);
        $canManageAccessRights = $this->actorCan(
            $hub,
            $actor,
            $firmId,
            FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS
        );

        return [
            'can_add' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_ADD),
            'can_view' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_VIEW),
            'can_delete' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_DELETE),
            'can_archive' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_ARCHIVE),
            'can_manage_member_rights' => $canManageAccessRights,
            'can_manage_firm_access' => $canManageFirmAccess,
            'is_firm_head' => $this->actorIsHead($hub, $actor, $firmId),
            'functionality_enabled' => $hub->hasFirmDocumentsFunctionality(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, slug: string, usage_count: int}>
     */
    public function listCategories(Hub $hub): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('firm_document_categories')) {
                return [];
            }

            $rows = DB::connection($connection)->table('firm_document_categories')
                ->orderBy('name')
                ->get(['id', 'name', 'slug']);

            $counts = [];
            if ($schema->hasTable('firm_documents') && $schema->hasColumn('firm_documents', 'category_id')) {
                $counts = DB::connection($connection)->table('firm_documents')
                    ->whereNotNull('category_id')
                    ->selectRaw('category_id, COUNT(*) as usage_count')
                    ->groupBy('category_id')
                    ->pluck('usage_count', 'category_id')
                    ->all();
            }

            return $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'usage_count' => (int) ($counts[$row->id] ?? 0),
            ])->values()->all();
        });
    }

    /**
     * @param  array{name: string, slug?: string|null}  $payload
     * @return array{id: int, name: string, slug: string, usage_count: int}
     */
    public function createCategory(Hub $hub, array $payload): array
    {
        $this->assertTarget($hub);

        $name = trim((string) ($payload['name'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? '')) ?: \Illuminate\Support\Str::slug($name);

        return $this->remoteDb->run($hub, function (string $connection) use ($name, $slug) {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('firm_document_categories')) {
                throw new InvalidArgumentException(
                    'This hub has not been migrated for Firm Document categories yet.'
                );
            }

            if (DB::connection($connection)->table('firm_document_categories')->where('name', $name)->exists()) {
                throw new InvalidArgumentException('That category name is already in use.');
            }
            if (DB::connection($connection)->table('firm_document_categories')->where('slug', $slug)->exists()) {
                throw new InvalidArgumentException('That slug is already in use.');
            }

            $id = (int) DB::connection($connection)->table('firm_document_categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'usage_count' => 0,
            ];
        });
    }

    /**
     * @param  array{name: string, slug?: string|null}  $payload
     * @return array{id: int, name: string, slug: string, usage_count: int}
     */
    public function updateCategory(Hub $hub, int $categoryId, array $payload): array
    {
        $this->assertTarget($hub);

        $name = trim((string) ($payload['name'] ?? ''));
        $slug = array_key_exists('slug', $payload) && filled($payload['slug'])
            ? trim((string) $payload['slug'])
            : \Illuminate\Support\Str::slug($name);

        return $this->remoteDb->run($hub, function (string $connection) use ($categoryId, $name, $slug) {
            $row = DB::connection($connection)->table('firm_document_categories')->where('id', $categoryId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Category not found on this hub.');
            }

            if (DB::connection($connection)->table('firm_document_categories')
                ->where('name', $name)
                ->where('id', '!=', $categoryId)
                ->exists()) {
                throw new InvalidArgumentException('That category name is already in use.');
            }
            if (DB::connection($connection)->table('firm_document_categories')
                ->where('slug', $slug)
                ->where('id', '!=', $categoryId)
                ->exists()) {
                throw new InvalidArgumentException('That slug is already in use.');
            }

            DB::connection($connection)->table('firm_document_categories')->where('id', $categoryId)->update([
                'name' => $name,
                'slug' => $slug,
                'updated_at' => now(),
            ]);

            $usage = 0;
            $schema = DB::connection($connection)->getSchemaBuilder();
            if ($schema->hasTable('firm_documents') && $schema->hasColumn('firm_documents', 'category_id')) {
                $usage = (int) DB::connection($connection)->table('firm_documents')
                    ->where('category_id', $categoryId)
                    ->count();
            }

            return [
                'id' => $categoryId,
                'name' => $name,
                'slug' => $slug,
                'usage_count' => $usage,
            ];
        });
    }

    public function deleteCategory(Hub $hub, int $categoryId): void
    {
        $this->assertTarget($hub);

        $this->remoteDb->run($hub, function (string $connection) use ($categoryId) {
            $row = DB::connection($connection)->table('firm_document_categories')->where('id', $categoryId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Category not found on this hub.');
            }

            $schema = DB::connection($connection)->getSchemaBuilder();
            if ($schema->hasTable('firm_documents') && $schema->hasColumn('firm_documents', 'category_id')) {
                $inUse = DB::connection($connection)->table('firm_documents')
                    ->where('category_id', $categoryId)
                    ->exists();
                if ($inUse) {
                    throw new InvalidArgumentException('Cannot delete a category that is used by firm documents.');
                }
            }

            DB::connection($connection)->table('firm_document_categories')->where('id', $categoryId)->delete();
        });
    }

    public function actorCan(Hub $hub, User $actor, int $firmId, string $right): bool
    {
        if ($this->actorIsHead($hub, $actor, $firmId)) {
            return true;
        }

        if ($right === FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS) {
            return $hub->hasFirmDocumentsFunctionality()
                && $this->matrix->userCan($hub, $actor, FirmDocumentAccessService::CAP_MANAGE_ACCESS_RIGHTS);
        }

        // Upload: Head (above) or hub-wide matrix — never via key-icon grants.
        if ($right === FirmDocumentAccessService::RIGHT_ADD) {
            return $hub->hasFirmDocumentsFunctionality()
                && $this->matrix->userCan($hub, $actor, 'firm_documents_add');
        }

        // Member grants on remote (match actor by email → remote user id).
        $grant = $this->remoteMemberGrant($hub, $actor, $firmId);
        if ($grant) {
            $ok = match ($right) {
                FirmDocumentAccessService::RIGHT_VIEW => (bool) $grant->can_view,
                FirmDocumentAccessService::RIGHT_DELETE => (bool) $grant->can_delete,
                FirmDocumentAccessService::RIGHT_ARCHIVE => (bool) $grant->can_archive,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        // Hub-wide matrix caps require Functionalities → Firm documents.
        if (! $hub->hasFirmDocumentsFunctionality()) {
            return false;
        }

        $cap = match ($right) {
            FirmDocumentAccessService::RIGHT_VIEW => 'firm_documents_view',
            FirmDocumentAccessService::RIGHT_DELETE => 'firm_documents_delete',
            FirmDocumentAccessService::RIGHT_ARCHIVE => 'firm_documents_archive',
            default => null,
        };

        return $cap !== null && $this->matrix->userCan($hub, $actor, $cap);
    }

    public function actorIsHead(Hub $hub, User $actor, int $firmId): bool
    {
        return (bool) $this->remoteDb->run($hub, function (string $connection) use ($actor, $firmId) {
            $firm = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
            if (! $firm || empty($firm->head_user_id)) {
                return false;
            }

            $remoteUser = DB::connection($connection)->table('users')
                ->where('email', $actor->email)
                ->first(['id']);

            if (! $remoteUser) {
                // Control-plane actor may share the same numeric id when testing on local-like DBs.
                return (int) $firm->head_user_id === (int) $actor->id;
            }

            return (int) $firm->head_user_id === (int) $remoteUser->id;
        });
    }

    private function remoteMemberGrant(Hub $hub, User $actor, int $firmId): ?object
    {
        return $this->remoteDb->run($hub, function (string $connection) use ($actor, $firmId) {
            if (! DB::connection($connection)->getSchemaBuilder()->hasTable('firm_document_member_rights')) {
                return null;
            }

            $remoteUser = DB::connection($connection)->table('users')
                ->where('email', $actor->email)
                ->first(['id', 'firm_id']);
            if (! $remoteUser || (int) ($remoteUser->firm_id ?? 0) !== $firmId) {
                return null;
            }

            return DB::connection($connection)->table('firm_document_member_rights')
                ->where('firm_id', $firmId)
                ->where('user_id', $remoteUser->id)
                ->first();
        });
    }

    private function assertCanOnRemote(Hub $hub, User $actor, int $firmId, string $right): void
    {
        if (! $this->actorCan($hub, $actor, $firmId, $right)) {
            throw new InvalidArgumentException('You do not have permission to '.$right.' firm documents for this firm.');
        }
    }

    private function assertCanManageFirmAccess(Hub $hub, User $actor, int $firmId): void
    {
        if (! $this->actorCanManageFirmAccess($hub, $actor, $firmId)) {
            throw new InvalidArgumentException('You do not have permission to decide which firms can see documents.');
        }
    }

    private function assertCanManageDocumentAccessRights(Hub $hub, User $actor, int $firmId): void
    {
        if (! $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS)) {
            throw new InvalidArgumentException('You do not have permission to manage document access rights.');
        }
    }

    public function actorCanManageFirmAccess(Hub $hub, User $actor, int $firmId): bool
    {
        if ($this->actorIsHead($hub, $actor, $firmId)) {
            return true;
        }

        return $hub->hasFirmDocumentsFunctionality()
            && $this->matrix->userCan($hub, $actor, FirmDocumentAccessService::CAP_MANAGE_FIRM_ACCESS);
    }

    private function assertFunctionality(Hub $hub): void
    {
        // Hub-wide matrix use still needs the functionality; Head/member paths
        // skip this via assertCanOnRemote → actorCan (Head bypasses the flag).
        // Keep this no-op so remote CRUD is not blocked for Heads.
    }

    private function assertFirmExists(Hub $hub, int $firmId): void
    {
        $exists = $this->remoteDb->run($hub, function (string $connection) use ($firmId) {
            return DB::connection($connection)->table('firms')->where('id', $firmId)->exists();
        });

        if (! $exists) {
            throw new InvalidArgumentException('Firm not found on this hub.');
        }
    }

    private function assertDocumentsTables(string $connection): void
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        if (! $schema->hasTable('firm_documents')) {
            throw new InvalidArgumentException(
                'This hub has not been migrated for Firm Documents yet. Run migrations on that hub’s database.'
            );
        }
    }

    private function assertVisibleFirmsTable(string $connection): void
    {
        $schema = DB::connection($connection)->getSchemaBuilder();
        if (! $schema->hasTable('firm_document_visible_firms')) {
            throw new InvalidArgumentException(
                'Visible-firm access is not available yet on this hub. Run migrations on that hub’s database.'
            );
        }
    }

    /**
     * @return list<int>
     */
    private function visibleGranteeFirmIdsOn(string $connection, int $ownerFirmId): array
    {
        if (! DB::connection($connection)->getSchemaBuilder()->hasTable('firm_document_visible_firms')) {
            return [];
        }

        return DB::connection($connection)->table('firm_document_visible_firms')
            ->where('owner_firm_id', $ownerFirmId)
            ->pluck('grantee_firm_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFirm(string $connection, int $firmId): array
    {
        $row = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
        if (! $row) {
            throw new InvalidArgumentException('Firm not found on this hub.');
        }

        $other = $row->compliance_visible_to_firm_id
            ? DB::connection($connection)->table('firms')->where('id', $row->compliance_visible_to_firm_id)->first()
            : null;
        $usersCount = (int) DB::connection($connection)->table('users')->where('firm_id', $firmId)->count();

        $headUser = null;
        if (isset($row->head_user_id) && $row->head_user_id) {
            $head = DB::connection($connection)->table('users')
                ->where('id', $row->head_user_id)
                ->first(['id', 'name', 'email']);
            if ($head) {
                $headUser = [
                    'id' => (int) $head->id,
                    'name' => (string) $head->name,
                    'email' => (string) $head->email,
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'is_central' => (bool) ($row->is_central ?? false),
            'users_count' => $usersCount,
            'head_user_id' => isset($row->head_user_id) && $row->head_user_id
                ? (int) $row->head_user_id
                : null,
            'head_user' => $headUser,
            'compliance_visibility' => [
                'visible_to_own' => (bool) ($row->compliance_visible_to_own ?? true),
                'visible_to_central' => (bool) ($row->compliance_visible_to_central ?? false),
                'visible_to_firm_id' => $row->compliance_visible_to_firm_id
                    ? (int) $row->compliance_visible_to_firm_id
                    : null,
                'visible_to_firm' => $other
                    ? ['id' => (int) $other->id, 'name' => (string) $other->name]
                    : null,
            ],
        ];
    }

    private function resolveRemoteActorId(Hub $hub, User $actor): ?int
    {
        return $this->remoteDb->run($hub, function (string $connection) use ($actor) {
            $remote = DB::connection($connection)->table('users')
                ->where('email', $actor->email)
                ->value('id');

            return $remote ? (int) $remote : null;
        });
    }

    /**
     * @param  array{folder_id?: mixed, folder_name?: mixed, parent_folder_id?: mixed}  $meta
     */
    private function resolveFolderIdOn(string $connection, int $firmId, array $meta, ?int $remoteUserId): ?int
    {
        if (! empty($meta['folder_id'])) {
            $folder = DB::connection($connection)->table('firm_document_folders')
                ->where('firm_id', $firmId)
                ->where('id', (int) $meta['folder_id'])
                ->first();
            if (! $folder) {
                throw new InvalidArgumentException('Folder not found for this firm.');
            }

            return (int) $folder->id;
        }

        $folderName = isset($meta['folder_name']) ? trim((string) $meta['folder_name']) : '';
        if ($folderName === '') {
            return null;
        }

        $parentId = isset($meta['parent_folder_id']) && $meta['parent_folder_id'] !== '' && $meta['parent_folder_id'] !== null
            ? (int) $meta['parent_folder_id']
            : null;

        if ($parentId !== null) {
            $parent = DB::connection($connection)->table('firm_document_folders')
                ->where('firm_id', $firmId)
                ->where('id', $parentId)
                ->first();
            if (! $parent) {
                throw new InvalidArgumentException('Parent folder not found for this firm.');
            }
        }

        return (int) DB::connection($connection)->table('firm_document_folders')->insertGetId([
            'firm_id' => $firmId,
            'parent_id' => $parentId,
            'name' => $folderName,
            'created_by' => $remoteUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDocument(
        string $connection,
        object $row,
        ?array $viewerRights = null,
        bool $includeFolder = true,
        bool $includeCategory = true,
    ): array {
        $attachments = DB::connection($connection)->table('firm_document_attachments')
            ->where('firm_document_id', $row->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($a) => [
                'id' => (int) $a->id,
                'firm_document_id' => (int) $a->firm_document_id,
                'original_name' => (string) $a->original_name,
                'file_path' => (string) $a->file_path,
                'file_url' => $a->file_url ?: Storage::disk('public')->url($a->file_path),
                'mime_type' => $a->mime_type,
                'size_bytes' => $a->size_bytes !== null ? (int) $a->size_bytes : null,
                'sort_order' => (int) ($a->sort_order ?? 0),
            ])
            ->values()
            ->all();

        $uploader = null;
        if (! empty($row->uploaded_by)) {
            $u = DB::connection($connection)->table('users')->where('id', $row->uploaded_by)->first(['id', 'name', 'email']);
            if ($u) {
                $uploader = [
                    'id' => (int) $u->id,
                    'name' => (string) $u->name,
                    'email' => (string) $u->email,
                ];
            }
        }

        $folderId = $includeFolder && isset($row->folder_id) && $row->folder_id
            ? (int) $row->folder_id
            : null;
        $folder = null;
        if ($folderId && DB::connection($connection)->getSchemaBuilder()->hasTable('firm_document_folders')) {
            $folderRow = DB::connection($connection)->table('firm_document_folders')
                ->where('id', $folderId)
                ->first(['id', 'name', 'parent_id']);
            if ($folderRow) {
                $folder = [
                    'id' => (int) $folderRow->id,
                    'name' => (string) $folderRow->name,
                    'parent_id' => $folderRow->parent_id ? (int) $folderRow->parent_id : null,
                ];
            }
        }

        $categoryId = $includeCategory && isset($row->category_id) && $row->category_id
            ? (int) $row->category_id
            : null;
        $category = null;
        if ($categoryId && DB::connection($connection)->getSchemaBuilder()->hasTable('firm_document_categories')) {
            $catRow = DB::connection($connection)->table('firm_document_categories')
                ->where('id', $categoryId)
                ->first(['id', 'name', 'slug']);
            if ($catRow) {
                $category = [
                    'id' => (int) $catRow->id,
                    'name' => (string) $catRow->name,
                    'slug' => (string) $catRow->slug,
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'firm_id' => (int) $row->firm_id,
            'folder_id' => $folderId,
            'folder' => $folder,
            'category_id' => $categoryId,
            'category' => $category,
            'title' => (string) $row->title,
            'description' => $row->description,
            'uploaded_by' => $row->uploaded_by ? (int) $row->uploaded_by : null,
            'uploader' => $uploader,
            'archived_at' => $row->archived_at,
            'archived_by' => $row->archived_by ? (int) $row->archived_by : null,
            'is_archived' => $row->archived_at !== null,
            'attachments' => $attachments,
            'viewer_rights' => $viewerRights,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
