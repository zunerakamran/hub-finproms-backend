<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\FirmDocumentMemberRight;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// WhiteLabelDatabaseService is resolved via app() in headedFirmIdFor().

/**
 * Resolves firm-document rights for a user against a firm.
 *
 * Order:
 * 1) Hub Functionalities → Firm documents must be ON (except assign-head is unrelated).
 * 2) Head of Firm → full rights for THEIR firm only (incl. member-rights grants).
 * 3) Member grant row → rights for THEIR firm only.
 * 4) Capabilities matrix firm_documents_* → hub-wide for ALL firms.
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

        // Same person, different user row (email match) — still Head.
        if (! filled($user->email)) {
            return false;
        }

        $head = User::query()->find($firm->head_user_id);

        return $head !== null
            && strcasecmp((string) $head->email, (string) $user->email) === 0;
    }

    public function can(User $user, Firm $firm, string $right, ?Hub $hub = null): bool
    {
        $hub = $hub ?? $this->hubs->current();

        if (! $this->functionalityEnabled($hub)) {
            return false;
        }

        // Head of Firm: full rights for their own firm (including granting member rights).
        if ($this->isHeadOfFirm($user, $firm)) {
            return true;
        }

        // Member grants are firm-scoped (own firm only).
        if ($right !== self::RIGHT_MANAGE_MEMBER_RIGHTS) {
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
        }

        // Only the Head of Firm manages member rights (not matrix / assign-head).
        if ($right === self::RIGHT_MANAGE_MEMBER_RIGHTS) {
            return false;
        }

        // Matrix caps apply across ALL firms on the hub.
        $cap = self::CAP_MAP[$right] ?? null;

        return $cap !== null && $this->matrix->userCan($hub, $user, $cap);
    }

    /**
     * @return array{
     *   can_add: bool,
     *   can_view: bool,
     *   can_delete: bool,
     *   can_archive: bool,
     *   can_manage_member_rights: bool,
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

        if (! $enabled) {
            return [
                'can_add' => false,
                'can_view' => false,
                'can_delete' => false,
                'can_archive' => false,
                'can_manage_member_rights' => false,
                'is_firm_head' => false,
                'firm_id' => $user->firm_id ? (int) $user->firm_id : null,
                'functionality_enabled' => false,
                'hub_wide' => $hubWide,
            ];
        }

        // Prefer "I am head of firm X" over relying on users.firm_id alone.
        $headedFirmId = $this->headedFirmIdFor($user, $hub);
        if ($headedFirmId) {
            return [
                'can_add' => true,
                'can_view' => true,
                'can_delete' => true,
                'can_archive' => true,
                'can_manage_member_rights' => true,
                'is_firm_head' => true,
                'firm_id' => $headedFirmId,
                'functionality_enabled' => true,
                'hub_wide' => $hubWide,
            ];
        }

        $firmId = $user->firm_id ? (int) $user->firm_id : null;

        $grant = null;
        if ($firmId && Schema::hasTable('firm_document_member_rights')) {
            $grant = FirmDocumentMemberRight::query()
                ->where('firm_id', $firmId)
                ->where('user_id', $user->id)
                ->first();
        }

        return [
            'can_add' => (bool) ($grant?->can_add) || $hubWide['can_add'],
            'can_view' => (bool) ($grant?->can_view) || $hubWide['can_view'],
            'can_delete' => (bool) ($grant?->can_delete) || $hubWide['can_delete'],
            'can_archive' => (bool) ($grant?->can_archive) || $hubWide['can_archive'],
            'can_manage_member_rights' => false,
            'is_firm_head' => false,
            'firm_id' => $firmId,
            'functionality_enabled' => true,
            'hub_wide' => $hubWide,
        ];
    }

    /**
     * Firm id where this user is Head of Firm (local DB, or remote hub DB by email).
     * Role-agnostic: manager, advisor, approver, client_admin, etc.
     */
    public function headedFirmIdFor(User $user, ?Hub $hub = null): ?int
    {
        $hub = $hub ?? $this->hubs->current();

        // Local / same-DB hub (content hub deploy, or Shared with local firms).
        if (Schema::hasColumn('firms', 'head_user_id')) {
            $localId = Firm::query()->where('head_user_id', $user->id)->value('id');
            if ($localId) {
                return (int) $localId;
            }

            // Email fallback if head was stored against another user row with the same email.
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

        // Acting remotely from Central: look up head by matching email on remote users.
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
