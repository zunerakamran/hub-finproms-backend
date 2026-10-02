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
     * @return array<string, mixed>
     */
    public function index(Hub $hub, User $actor, int $firmId, string $scope, int $perPage, int $page = 1): array
    {
        $this->assertTarget($hub);
        $this->assertFunctionality($hub);
        $this->assertFirmExists($hub, $firmId);
        $this->assertCanOnRemote($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_VIEW);

        $rights = $this->rightsPayload($hub, $actor, $firmId);

        $payload = $this->remoteDb->run($hub, function (string $connection) use ($firmId, $scope, $perPage, $page) {
            $this->assertDocumentsTables($connection);

            $query = DB::connection($connection)->table('firm_documents')
                ->where('firm_id', $firmId)
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            if ($scope === 'archived') {
                $query->whereNotNull('archived_at');
            } elseif ($scope !== 'all') {
                $query->whereNull('archived_at');
            }

            $total = (clone $query)->count();
            $perPage = max(1, min(100, $perPage));
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $lastPage));
            $rows = $query->forPage($page, $perPage)->get();

            $documents = $rows->map(function ($row) use ($connection) {
                return $this->serializeDocument($connection, $row);
            })->values()->all();

            return [
                'firm' => $this->serializeFirm($connection, $firmId),
                'documents' => $documents,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ];
        });

        return array_merge($payload, ['rights' => $rights]);
    }

    /**
     * @param  list<UploadedFile>  $files
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

        $document = $this->remoteDb->run($hub, function (string $connection) use ($firmId, $title, $description, $files, $remoteUserId) {
            $this->assertDocumentsTables($connection);

            $docId = (int) DB::connection($connection)->table('firm_documents')->insertGetId([
                'firm_id' => $firmId,
                'title' => $title,
                'description' => $description,
                'uploaded_by' => $remoteUserId,
                'archived_at' => null,
                'archived_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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

            return $this->serializeDocument($connection, $row);
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
        return [
            'can_add' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_ADD),
            'can_view' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_VIEW),
            'can_delete' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_DELETE),
            'can_archive' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_ARCHIVE),
            'can_manage_member_rights' => $this->actorCan($hub, $actor, $firmId, FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS),
            'is_firm_head' => $this->actorIsHead($hub, $actor, $firmId),
            'functionality_enabled' => $hub->hasFirmDocumentsFunctionality(),
        ];
    }

    public function actorCan(Hub $hub, User $actor, int $firmId, string $right): bool
    {
        if (! $hub->hasFirmDocumentsFunctionality()) {
            return false;
        }

        if ($this->actorIsHead($hub, $actor, $firmId)) {
            return true;
        }

        if ($right === FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS) {
            return false;
        }

        // Member grants on remote (match actor by email → remote user id).
        if ($right !== FirmDocumentAccessService::RIGHT_MANAGE_MEMBER_RIGHTS) {
            $grant = $this->remoteMemberGrant($hub, $actor, $firmId);
            if ($grant) {
                $ok = match ($right) {
                    FirmDocumentAccessService::RIGHT_ADD => (bool) $grant->can_add,
                    FirmDocumentAccessService::RIGHT_VIEW => (bool) $grant->can_view,
                    FirmDocumentAccessService::RIGHT_DELETE => (bool) $grant->can_delete,
                    FirmDocumentAccessService::RIGHT_ARCHIVE => (bool) $grant->can_archive,
                    default => false,
                };
                if ($ok) {
                    return true;
                }
            }
        }

        $cap = match ($right) {
            FirmDocumentAccessService::RIGHT_ADD => 'firm_documents_add',
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

    private function assertFunctionality(Hub $hub): void
    {
        if (! $hub->hasFirmDocumentsFunctionality()) {
            throw new InvalidArgumentException(
                'Firm documents are disabled for this hub. Enable Functionalities → Firm documents first.'
            );
        }
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
     * @return array<string, mixed>
     */
    private function serializeDocument(string $connection, object $row): array
    {
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

        return [
            'id' => (int) $row->id,
            'firm_id' => (int) $row->firm_id,
            'title' => (string) $row->title,
            'description' => $row->description,
            'uploaded_by' => $row->uploaded_by ? (int) $row->uploaded_by : null,
            'uploader' => $uploader,
            'archived_at' => $row->archived_at,
            'archived_by' => $row->archived_by ? (int) $row->archived_by : null,
            'is_archived' => $row->archived_at !== null,
            'attachments' => $attachments,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
