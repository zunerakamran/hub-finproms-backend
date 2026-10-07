<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmDocument;
use App\Models\FirmDocumentMemberRight;
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
 * 3) Firm-wide member grant (firm_document_id null) → mainly can_add (upload).
 * 4) Capabilities matrix firm_documents_* → hub-wide for ALL firms (requires
 *    Functionalities → Firm documents ON).
 */
class FirmDocumentAccessService
{
    public const RIGHT_ADD = 'add';

    public const RIGHT_VIEW = 'view';

    public const RIGHT_DELETE = 'delete';

    public const RIGHT_ARCHIVE = 'archive';

    public const RIGHT_MANAGE_MEMBER_RIGHTS = 'manage_member_rights';

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
            return false;
        }

        if ($document !== null && (int) $document->firm_id === (int) $firm->id) {
            $docGrant = $this->documentGrant($user, $firm, (int) $document->id);
            if ($docGrant && $this->grantAllows($docGrant, $right)) {
                return true;
            }
        }

        // Firm-wide row (null document_id): used for upload (can_add) and legacy library access.
        $firmGrant = $this->firmWideGrant($user, $firm);
        if ($firmGrant && $this->grantAllows($firmGrant, $right)) {
            return true;
        }

        // Library entry (view without a specific document): any per-doc grant unlocks the page.
        if ($right === self::RIGHT_VIEW && $document === null && $this->hasAnyDocumentGrant($user, $firm)) {
            return true;
        }

        if (! $this->functionalityEnabled($hub)) {
            return false;
        }

        $cap = self::CAP_MAP[$right] ?? null;

        return $cap !== null && $this->matrix->userCan($hub, $user, $cap);
    }

    /**
     * Per-document effective rights for the viewer (actions on that row).
     *
     * @return array{can_view: bool, can_delete: bool, can_archive: bool, can_manage_member_rights: bool}
     */
    public function documentRightsFor(User $user, Firm $firm, FirmDocument $document, ?Hub $hub = null): array
    {
        return [
            'can_view' => $this->can($user, $firm, self::RIGHT_VIEW, $hub, $document),
            'can_delete' => $this->can($user, $firm, self::RIGHT_DELETE, $hub, $document),
            'can_archive' => $this->can($user, $firm, self::RIGHT_ARCHIVE, $hub, $document),
            'can_manage_member_rights' => $this->can($user, $firm, self::RIGHT_MANAGE_MEMBER_RIGHTS, $hub),
        ];
    }

    /**
     * @return array{
     *   can_add: bool,
     *   can_view: bool,
     *   can_delete: bool,
     *   can_archive: bool,
     *   can_manage_member_rights: bool,
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

        $headedFirmId = $this->headedFirmIdFor($user, $hub);
        if ($headedFirmId) {
            return [
                'can_add' => true,
                'can_view' => true,
                'can_delete' => true,
                'can_archive' => true,
                'can_manage_member_rights' => true,
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
        if ($firmId && Schema::hasTable('firm_document_member_rights')) {
            $firm = new Firm(['id' => $firmId]);
            $firm->exists = true;
            $firmGrant = $this->firmWideGrant($user, $firm);
            $hasDocGrant = $this->hasAnyDocumentGrant($user, $firm);
        }

        $canView = (bool) ($firmGrant?->can_view) || $hasDocGrant || $hubWide['can_view'];
        $canAdd = (bool) ($firmGrant?->can_add) || $hubWide['can_add'];
        $canDelete = (bool) ($firmGrant?->can_delete) || $hubWide['can_delete'];
        $canArchive = (bool) ($firmGrant?->can_archive) || $hubWide['can_archive'];

        return [
            'can_add' => $canAdd,
            'can_view' => $canView,
            'can_delete' => $canDelete,
            'can_archive' => $canArchive,
            'can_manage_member_rights' => false,
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
        if (! Schema::hasTable('firm_document_member_rights')) {
            return [];
        }

        if ($user->firm_id === null || (int) $user->firm_id !== (int) $firm->id) {
            return [];
        }

        return FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->where('user_id', $user->id)
            ->whereNotNull('firm_document_id')
            ->where('can_view', true)
            ->pluck('firm_document_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
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
}
