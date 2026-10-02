<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmDocumentMemberRight;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves firm-document rights for a user against a firm.
 * Head of Firm always has full rights for their firm.
 * Otherwise: member grant row OR role capability from the matrix.
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
        self::RIGHT_MANAGE_MEMBER_RIGHTS => 'firm_documents_manage_member_rights',
    ];

    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly HubService $hubs,
    ) {}

    public function isHeadOfFirm(User $user, Firm $firm): bool
    {
        if ($firm->head_user_id === null || (int) $firm->head_user_id !== (int) $user->id) {
            return false;
        }

        // Stale head row if the user left the firm.
        return $user->firm_id !== null && (int) $user->firm_id === (int) $firm->id;
    }

    public function can(User $user, Firm $firm, string $right, ?Hub $hub = null): bool
    {
        $hub = $hub ?? $this->hubs->current();

        if ($this->isHeadOfFirm($user, $firm)) {
            return true;
        }

        // Assign-head roles may manage member rights for support.
        if ($right === self::RIGHT_MANAGE_MEMBER_RIGHTS
            && $this->matrix->userCan($hub, $user, 'dashboard_assign_firm_head')) {
            return true;
        }

        $grant = $this->memberGrant($user, $firm);
        if ($grant) {
            $granted = match ($right) {
                self::RIGHT_ADD => (bool) $grant->can_add,
                self::RIGHT_VIEW => (bool) $grant->can_view,
                self::RIGHT_DELETE => (bool) $grant->can_delete,
                self::RIGHT_ARCHIVE => (bool) $grant->can_archive,
                default => false,
            };
            if ($granted) {
                return true;
            }
        }

        $cap = self::CAP_MAP[$right] ?? null;
        if ($cap && $this->matrix->userCan($hub, $user, $cap)) {
            // Role-level document caps still require firm membership (except manage rights via assign-head above).
            if ($right === self::RIGHT_MANAGE_MEMBER_RIGHTS) {
                return true;
            }

            return $user->firm_id !== null && (int) $user->firm_id === (int) $firm->id;
        }

        return false;
    }

    /**
     * Aggregated rights across the user's own firm (or all firms for assign-head / role caps).
     *
     * @return array{can_add: bool, can_view: bool, can_delete: bool, can_archive: bool, can_manage_member_rights: bool, is_firm_head: bool, firm_id: ?int}
     */
    public function effectiveRightsSummary(User $user, ?Hub $hub = null): array
    {
        $hub = $hub ?? $this->hubs->current();
        $firmId = $user->firm_id ? (int) $user->firm_id : null;
        $isHead = false;

        if ($firmId && Schema::hasColumn('firms', 'head_user_id')) {
            $firm = Firm::query()->find($firmId);
            if ($firm && $this->isHeadOfFirm($user, $firm)) {
                $isHead = true;

                return [
                    'can_add' => true,
                    'can_view' => true,
                    'can_delete' => true,
                    'can_archive' => true,
                    'can_manage_member_rights' => true,
                    'is_firm_head' => true,
                    'firm_id' => $firmId,
                ];
            }
        }

        $grant = null;
        if ($firmId && Schema::hasTable('firm_document_member_rights')) {
            $grant = FirmDocumentMemberRight::query()
                ->where('firm_id', $firmId)
                ->where('user_id', $user->id)
                ->first();
        }

        $canAdd = (bool) ($grant?->can_add)
            || $this->matrix->userCan($hub, $user, 'firm_documents_add');
        $canView = (bool) ($grant?->can_view)
            || $this->matrix->userCan($hub, $user, 'firm_documents_view');
        $canDelete = (bool) ($grant?->can_delete)
            || $this->matrix->userCan($hub, $user, 'firm_documents_delete');
        $canArchive = (bool) ($grant?->can_archive)
            || $this->matrix->userCan($hub, $user, 'firm_documents_archive');
        $canManage = $this->matrix->userCan($hub, $user, 'firm_documents_manage_member_rights')
            || $this->matrix->userCan($hub, $user, 'dashboard_assign_firm_head');

        // Role caps without firm membership: treat as hub-wide support tools.
        if (! $firmId) {
            return [
                'can_add' => $this->matrix->userCan($hub, $user, 'firm_documents_add'),
                'can_view' => $this->matrix->userCan($hub, $user, 'firm_documents_view'),
                'can_delete' => $this->matrix->userCan($hub, $user, 'firm_documents_delete'),
                'can_archive' => $this->matrix->userCan($hub, $user, 'firm_documents_archive'),
                'can_manage_member_rights' => $canManage,
                'is_firm_head' => false,
                'firm_id' => null,
            ];
        }

        return [
            'can_add' => $canAdd,
            'can_view' => $canView,
            'can_delete' => $canDelete,
            'can_archive' => $canArchive,
            'can_manage_member_rights' => $canManage || $isHead,
            'is_firm_head' => $isHead,
            'firm_id' => $firmId,
        ];
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

    private function memberGrant(User $user, Firm $firm): ?FirmDocumentMemberRight
    {
        if (! Schema::hasTable('firm_document_member_rights')) {
            return null;
        }

        if ($user->firm_id === null || (int) $user->firm_id !== (int) $firm->id) {
            return null;
        }

        return FirmDocumentMemberRight::query()
            ->where('firm_id', $firm->id)
            ->where('user_id', $user->id)
            ->first();
    }
}
