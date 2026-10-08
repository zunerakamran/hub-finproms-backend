<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmDocument;
use App\Models\FirmDocumentFirmRight;
use App\Models\FirmDocumentMemberRight;
use App\Models\FirmDocumentVisibleFirm;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves firm-document rights for a user against a firm / document.
 *
 * Order:
 * 1) Head of Firm (any role) → full rights for THEIR firm.
 * 2) Per-document member grant → view / delete / archive for that document.
 * 3) Firm-level grant on a document → every member of the grantee firm
 *    receives those rights for that document.
 * 4) Firm-wide member grant (firm_document_id null) → mainly can_add (upload).
 * 5) Capabilities matrix firm_documents_* → hub-wide for ALL firms (requires
 *    Functionalities → Firm documents ON).
 */
class FirmDocumentAccessService
{
    public const RIGHT_ADD = 'add';

    public const RIGHT_VIEW = 'view';

    public const RIGHT_DELETE = 'delete';

    public const RIGHT_ARCHIVE = 'archive';

    public const RIGHT_MANAGE_MEMBER_RIGHTS = 'manage_member_rights';

    public const CAP_MANAGE_FIRM_ACCESS = 'firm_documents_manage_firm_access';

    private const CAP_MAP = [
        self::RIGHT_ADD => 'firm_documents_add',
        self::RIGHT_VIEW => 'firm_documents_view',
        self::RIGHT_DELETE => 'firm_documents_delete',
        self::RIGHT_ARCHIVE => 'firm_documents_archive',
    ];

    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly HubService $hubs,
    ) {}

    public function functionalityEnabled(?Hub $hub = null): bool
    {
        $hub = $hub ?? $this->hubs->current();

        return $hub->hasFirmDocumentsFunctionality();
    }

    public function isHeadOfFirm(User $user, Firm $firm): bool
    {
        if ($firm->head_user_id === null) {
            return false;
        }

        if ((int) $firm->head_user_id === (int) $user->id) {
            return true;
        }

        if (! filled($user->email)) {
            return false;
        }

        $head = User::query()->find($firm->head_user_id);

        return $head !== null
            && strcasecmp((string) $head->email, (string) $user->email) === 0;
    }

    public function can(User $user, Firm $firm, string $right, ?Hub $hub = null, ?FirmDocument $document = null): bool
    {
        $hub = $hub ?? $this->hubs->current();

        if ($this->isHeadOfFirm($user, $firm)) {
            return true;
        }

        if ($right === self::RIGHT_MANAGE_MEMBER_RIGHTS) {
            // Head already returned true above. Matrix holders with firm-access may
            // also manage document member grants on any firm.
            return $this->canManageFirmAccessViaMatrix($user, $hub);
        }

        if ($document !== null && (int) $document->firm_id === (int) $firm->id) {
            $docGrant = $this->documentGrant($user, $firm, (int) $document->id);
            if ($docGrant && $this->grantAllows($docGrant, $right)) {
                return true;
            }
        }

        // Central / Network → other firm: every member of the grantee firm gets the rights.
        if ($document !== null && $this->firmLevelGrantAllows($user, $document, $right)) {
            return true;
        }

        // Firm-wide row (null document_id): used for upload (can_add) and legacy library access.
        $firmGrant = $this->firmWideGrant($user, $firm);
        if ($firmGrant && $this->grantAllows($firmGrant, $right)) {
            return true;
        }

        // Library entry (view without a specific document): any per-doc or firm-share grant unlocks the page.
        if ($right === self::RIGHT_VIEW && $document === null) {
            if ($this->hasAnyDocumentGrant($user, $firm) || $this->hasAnySharedFirmGrant($user, $firm)) {
                return true;
            }
        }

        // Upload unlock via firm-share can_add on any doc shared to this firm.
        if ($right === self::RIGHT_ADD && $document === null && $this->hasSharedFirmAddGrant($user, $firm)) {
            return true;
        }

        if (! $this->functionalityEnabled($hub)) {
            return false;
        }

        $cap = self::CAP_MAP[$right] ?? null;

        return $cap !== null && $this->matrix->userCan($hub, $user, $cap);
    }

    /**
     * Who may set the visible-firms allowlist / firm-level document grants
     * for a firm’s documents (any firm, not only Central / Network).
     */
    public function canManageFirmAccess(User $user, Firm $owningFirm, ?Hub $hub = null): bool
    {
        $hub = $hub ?? $this->hubs->current();

        if ($this->isHeadOfFirm($user, $owningFirm)) {
            return true;
        }

        return $this->canManageFirmAccessViaMatrix($user, $hub);
    }

    public function canManageFirmAccessViaMatrix(User $user, ?Hub $hub = null): bool
    {
        $hub = $hub ?? $this->hubs->current();

        return $this->functionalityEnabled($hub)
            && $this->matrix->userCan($hub, $user, self::CAP_MANAGE_FIRM_ACCESS);
    }

    /**
     * Per-document effective rights for the viewer (actions on that row).
     *
     * @return array{can_view: bool, can_delete: bool, can_archive: bool, can_manage_member_rights: bool}
     */
    public function documentRightsFor(User $user, Firm $firm, FirmDocument $document, ?Hub $hub = null): array
    {
        // Resolve against the document's owning firm (shared Central docs may appear in another firm's library).
        $owningFirm = (int) $document->firm_id === (int) $firm->id
            ? $firm
            : ($document->relationLoaded('firm') && $document->firm
                ? $document->firm
                : Firm::query()->find((int) $document->firm_id) ?? $firm);

        $canManage = $this->can($user, $owningFirm, self::RIGHT_MANAGE_MEMBER_RIGHTS, $hub)
            || $this->canManageFirmAccess($user, $owningFirm, $hub);

        return [
            'can_view' => $this->can($user, $owningFirm, self::RIGHT_VIEW, $hub, $document),
            'can_delete' => $this->can($user, $owningFirm, self::RIGHT_DELETE, $hub, $document),
            'can_archive' => $this->can($user, $owningFirm, self::RIGHT_ARCHIVE, $hub, $document),
            'can_manage_member_rights' => $canManage,
            'access_mode' => 'mixed',
        ];
    }

    /**
     * @return array{
     *   can_add: bool,
     *   can_view: bool,
     *   can_delete: bool,
     *   can_archive: bool,
     *   can_manage_member_rights: bool,
     *   can_manage_firm_access: bool,
     *   can_manage_categories: bool,
     *   is_firm_head: bool,
     *   firm_id: ?int,
     *   functionality_enabled: bool,
     *   hub_wide: array{can_add: bool, can_view: bool, can_delete: bool, can_archive: bool}
     * }
     */
    public function effectiveRightsSummary(User $user, ?Hub $hub = null): array
    {
        $hub = $hub ?? $this->hubs->current();
        $enabled = $this->functionalityEnabled($hub);

        $hubWide = [
            'can_add' => $enabled && $this->matrix->userCan($hub, $user, 'firm_documents_add'),
            'can_view' => $enabled && $this->matrix->userCan($hub, $user, 'firm_documents_view'),
            'can_delete' => $enabled && $this->matrix->userCan($hub, $user, 'firm_documents_delete'),
            'can_archive' => $enabled && $this->matrix->userCan($hub, $user, 'firm_documents_archive'),
        ];

        $canManageCategories = $enabled && $this->matrix->userCan($hub, $user, 'firm_documents_manage_categories');
        $canManageFirmAccess = $this->canManageFirmAccessViaMatrix($user, $hub);

        $headedFirmId = $this->headedFirmIdFor($user, $hub);
        if ($headedFirmId) {
            return [
                'can_add' => true,
                'can_view' => true,
                'can_delete' => true,
                'can_archive' => true,
                'can_manage_member_rights' => true,
                'can_manage_firm_access' => true,
                'can_manage_categories' => $canManageCategories,
                'is_firm_head' => true,
                'firm_id' => $headedFirmId,
                'functionality_enabled' => $enabled,
                'hub_wide' => $hubWide,
            ];
        }

        $firmId = $user->firm_id ? (int) $user->firm_id : null;
        $firmGrant = null;
        $hasDocGrant = false;
        $hasSharedView = false;
        $hasSharedAdd = false;
        if ($firmId) {
            $firm = new Firm(['id' => $firmId]);
            $firm->exists = true;

            // Own-firm member grants (per-doc / firm-wide).
            if (Schema::hasTable('firm_document_member_rights')) {
                $firmGrant = $this->firmWideGrant($user, $firm);
                $hasDocGrant = $this->hasAnyDocumentGrant($user, $firm);
            }

            // Shared docs: explicit firm grants OR Document access control allowlist.
            // Must run even when member_rights table is missing — otherwise
            // allowlisted firm users never get firm_documents_view for the nav.
            $hasSharedView = $this->hasAnySharedFirmGrant($user, $firm);
            $hasSharedAdd = $this->hasSharedFirmAddGrant($user, $firm);
        }

        $canView = (bool) ($firmGrant?->can_view) || $hasDocGrant || $hasSharedView || $hubWide['can_view'];
        $canAdd = (bool) ($firmGrant?->can_add) || $hasSharedAdd || $hubWide['can_add'];
        $canDelete = (bool) ($firmGrant?->can_delete) || $hubWide['can_delete'];
        $canArchive = (bool) ($firmGrant?->can_archive) || $hubWide['can_archive'];

        return [
            'can_add' => $canAdd,
            'can_view' => $canView,
            'can_delete' => $canDelete,
            'can_archive' => $canArchive,
            'can_manage_member_rights' => false,
            'can_manage_firm_access' => $canManageFirmAccess,
            'can_manage_categories' => $canManageCategories,
            'is_firm_head' => false,
            'firm_id' => $firmId,
            'functionality_enabled' => $enabled || $canView || $canAdd,
            'hub_wide' => $hubWide,
        ];
    }

    /**
     * Whether the user sees every document for the firm (vs filtered to grants).
     */
    public function seesAllFirmDocuments(User $user, Firm $firm, ?Hub $hub = null): bool
    {
        $hub = $hub ?? $this->hubs->current();

        if ($this->isHeadOfFirm($user, $firm)) {
            return true;
        }

        if ($this->functionalityEnabled($hub) && $this->matrix->userCan($hub, $user, 'firm_documents_view')) {
            return true;
        }

        $firmGrant = $this->firmWideGrant($user, $firm);

        return (bool) ($firmGrant?->can_view);
    }

    /**
     * Document ids the user may view when not seeing all.
     *
     * @return list<int>
     */
    public function visibleDocumentIds(User $user, Firm $firm): array
    {
        $ids = [];

        if (Schema::hasTable('firm_document_member_rights')
            && $user->firm_id !== null
            && (int) $user->firm_id === (int) $firm->id
        ) {
            $ids = FirmDocumentMemberRight::query()
                ->where('firm_id', $firm->id)
                ->where('user_id', $user->id)
                ->whereNotNull('firm_document_id')
                ->where('can_view', true)
                ->pluck('firm_document_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // Docs owned by this firm that were shared to the user's firm (when browsing owner).
        if ($user->firm_id
            && Schema::hasTable('firm_document_firm_rights')
        ) {
            $shared = FirmDocumentFirmRight::query()
                ->where('grantee_firm_id', (int) $user->firm_id)
                ->where('can_view', true)
                ->whereIn('firm_document_id', function ($q) use ($firm) {
                    $q->select('id')->from('firm_documents')->where('firm_id', $firm->id);
                })
                ->pluck('firm_document_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_merge($ids, $shared);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Document ids from other firms shared to this firm with can_view.
     *
     * Sources:
     * 1) Explicit firm_document_firm_rights with can_view
     * 2) Document access control allowlist (firm_document_visible_firms) — view by
     *    default for every document of the owning firm, unless explicitly revoked
     *    (firm_rights row with can_view = false)
     *
     * @return list<int>
     */
    public function sharedDocumentIdsForFirm(Firm $granteeFirm): array
    {
        $ids = [];

        if (Schema::hasTable('firm_document_firm_rights')) {
            $ids = FirmDocumentFirmRight::query()
                ->where('grantee_firm_id', $granteeFirm->id)
                ->where('can_view', true)
                ->whereIn('firm_document_id', function ($q) use ($granteeFirm) {
                    $q->select('id')->from('firm_documents')->where('firm_id', '!=', $granteeFirm->id);
                })
                ->pluck('firm_document_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (Schema::hasTable('firm_document_visible_firms')) {
            $ownerIds = FirmDocumentVisibleFirm::query()
                ->where('grantee_firm_id', $granteeFirm->id)
                ->pluck('owner_firm_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ownerIds !== []) {
                $allowlistDocIds = FirmDocument::query()
                    ->whereIn('firm_id', $ownerIds)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $revoked = [];
                if (Schema::hasTable('firm_document_firm_rights') && $allowlistDocIds !== []) {
                    $revoked = FirmDocumentFirmRight::query()
                        ->where('grantee_firm_id', $granteeFirm->id)
                        ->where('can_view', false)
                        ->whereIn('firm_document_id', $allowlistDocIds)
                        ->pluck('firm_document_id')
                        ->map(fn ($id) => (int) $id)
                        ->all();
                }

                $ids = array_merge($ids, array_values(array_diff($allowlistDocIds, $revoked)));
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @deprecated Use sharedDocumentIdsForFirm()
     *
     * @return list<int>
     */
    public function sharedCentralDocumentIdsForFirm(Firm $granteeFirm): array
    {
        return $this->sharedDocumentIdsForFirm($granteeFirm);
    }

    public function headedFirmIdFor(User $user, ?Hub $hub = null): ?int
    {
        $hub = $hub ?? $this->hubs->current();

        if (Schema::hasColumn('firms', 'head_user_id')) {
            $localId = Firm::query()->where('head_user_id', $user->id)->value('id');
            if ($localId) {
                return (int) $localId;
            }

            if (filled($user->email)) {
                $headIds = User::query()
                    ->where('email', $user->email)
                    ->pluck('id')
                    ->all();
                if ($headIds !== []) {
                    $byEmail = Firm::query()->whereIn('head_user_id', $headIds)->value('id');
                    if ($byEmail) {
                        return (int) $byEmail;
                    }
                }
            }
        }

        if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
            try {
                return app(WhiteLabelDatabaseService::class)->run($hub, function (string $connection) use ($user) {
                    if (! DB::connection($connection)->getSchemaBuilder()->hasColumn('firms', 'head_user_id')) {
                        return null;
                    }

                    $remoteUserIds = DB::connection($connection)->table('users')
                        ->where('email', $user->email)
                        ->pluck('id')
                        ->all();
                    if ($remoteUserIds === []) {
                        $firmId = DB::connection($connection)->table('firms')
                            ->where('head_user_id', $user->id)
                            ->value('id');

                        return $firmId ? (int) $firmId : null;
                    }

                    $firmId = DB::connection($connection)->table('firms')
                        ->whereIn('head_user_id', $remoteUserIds)
                        ->value('id');

                    return $firmId ? (int) $firmId : null;
                });
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    public function clearGrantsForUser(User $user): void
    {
        if (! Schema::hasTable('firm_document_member_rights')) {
            return;
        }

        FirmDocumentMemberRight::query()->where('user_id', $user->id)->delete();
    }

    public function clearHeadIfUser(User $user): void
    {
        if (! Schema::hasColumn('firms', 'head_user_id')) {
            return;
        }

        Firm::query()->where('head_user_id', $user->id)->update(['head_user_id' => null]);
    }

    private function grantAllows(FirmDocumentMemberRight $grant, string $right): bool
    {
        return match ($right) {
            self::RIGHT_ADD => (bool) $grant->can_add,
            self::RIGHT_VIEW => (bool) $grant->can_view,
            self::RIGHT_DELETE => (bool) $grant->can_delete,
            self::RIGHT_ARCHIVE => (bool) $grant->can_archive,
            default => false,
        };
    }

    private function documentGrant(User $user, Firm $firm, int $documentId): ?FirmDocumentMemberRight
    {
        if (! Schema::hasTable('firm_document_member_rights')) {
            return null;
        }

        if ($user->firm_id === null || (int) $user->firm_id !== (int) $firm->id) {
            return null;
        }

        return FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->where('firm_document_id', $documentId)
            ->where('user_id', $user->id)
            ->first();
    }

    private function firmWideGrant(User $user, Firm $firm): ?FirmDocumentMemberRight
    {
        if (! Schema::hasTable('firm_document_member_rights')) {
            return null;
        }

        if ($user->firm_id === null || (int) $user->firm_id !== (int) $firm->id) {
            return null;
        }

        return FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->whereNull('firm_document_id')
            ->where('user_id', $user->id)
            ->first();
    }

    private function hasAnyDocumentGrant(User $user, Firm $firm): bool
    {
        if (! Schema::hasTable('firm_document_member_rights')) {
            return false;
        }

        if ($user->firm_id === null || (int) $user->firm_id !== (int) $firm->id) {
            return false;
        }

        return FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->where('user_id', $user->id)
            ->whereNotNull('firm_document_id')
            ->where(function ($q) {
                $q->where('can_view', true)
                    ->orWhere('can_delete', true)
                    ->orWhere('can_archive', true)
                    ->orWhere('can_add', true);
            })
            ->exists();
    }

    private function firmLevelGrantAllows(User $user, FirmDocument $document, string $right): bool
    {
        $grant = $this->firmLevelGrantForUser($user, (int) $document->id);
        if ($grant) {
            return match ($right) {
                self::RIGHT_ADD => (bool) $grant->can_add,
                self::RIGHT_VIEW => (bool) $grant->can_view,
                self::RIGHT_DELETE => (bool) $grant->can_delete,
                self::RIGHT_ARCHIVE => (bool) $grant->can_archive,
                default => false,
            };
        }

        // No firm-rights row yet: Document access control allowlist grants view
        // for every member of the grantee firm.
        if ($right === self::RIGHT_VIEW && $user->firm_id) {
            return $this->firmIsAllowlistedForOwner((int) $user->firm_id, (int) $document->firm_id);
        }

        return false;
    }

    /**
     * Whether $granteeFirmId is on $ownerFirmId’s Document access control allowlist.
     */
    public function firmIsAllowlistedForOwner(int $granteeFirmId, int $ownerFirmId): bool
    {
        if ($granteeFirmId === $ownerFirmId || ! Schema::hasTable('firm_document_visible_firms')) {
            return false;
        }

        return FirmDocumentVisibleFirm::query()
            ->where('owner_firm_id', $ownerFirmId)
            ->where('grantee_firm_id', $granteeFirmId)
            ->exists();
    }

    private function firmLevelGrantForUser(User $user, int $documentId): ?FirmDocumentFirmRight
    {
        if (! Schema::hasTable('firm_document_firm_rights') || $user->firm_id === null) {
            return null;
        }

        return FirmDocumentFirmRight::query()
            ->where('firm_document_id', $documentId)
            ->where('grantee_firm_id', (int) $user->firm_id)
            ->first();
    }

    /**
     * Any other-firm document shared to this firm with a usable right.
     */
    private function hasAnySharedFirmGrant(User $user, Firm $firm): bool
    {
        if ($user->firm_id === null || (int) $user->firm_id !== (int) $firm->id) {
            return false;
        }

        if (Schema::hasTable('firm_document_firm_rights')
            && FirmDocumentFirmRight::query()
                ->where('grantee_firm_id', $firm->id)
                ->where(function ($q) {
                    $q->where('can_view', true)
                        ->orWhere('can_add', true)
                        ->orWhere('can_delete', true)
                        ->orWhere('can_archive', true);
                })
                ->exists()
        ) {
            return true;
        }

        // Allowlisted under another firm’s Document access control ⇒ library unlock.
        return Schema::hasTable('firm_document_visible_firms')
            && FirmDocumentVisibleFirm::query()
                ->where('grantee_firm_id', $firm->id)
                ->exists();
    }

    private function hasSharedFirmAddGrant(User $user, Firm $firm): bool
    {
        if (! Schema::hasTable('firm_document_firm_rights')) {
            return false;
        }

        if ($user->firm_id === null || (int) $user->firm_id !== (int) $firm->id) {
            return false;
        }

        return FirmDocumentFirmRight::query()
            ->where('grantee_firm_id', $firm->id)
            ->where('can_add', true)
            ->exists();
    }
}
