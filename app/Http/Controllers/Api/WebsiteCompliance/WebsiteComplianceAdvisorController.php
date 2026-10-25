<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Services\CapabilitiesMatrixService;
use App\Services\ComplianceSeparationService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WebsiteComplianceAdvisorController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ComplianceSeparationService $separation,
        private readonly FirmComplianceVisibilityService $firmVisibility,
    ) {}

    /**
     * @deprecated Prefer websiteAdmins() — kept for older clients.
     * List advisors for legacy deployment assignment flows.
     */
    public function advisors(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        if (
            ! $this->gate->can($user, 'wc_request_deployments')
            && ! $this->gate->can($user, 'wc_view_all_deployments')
            && ! $this->gate->can($user, 'wc_assign_website_templates')
            && ! $this->gate->can($user, 'wc_deploy_websites')
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $advisors = User::query()
            ->with('firm:id,name,is_central')
            ->where(function ($q) {
                $q->where('role', User::ROLE_ADVISOR)
                    ->orWhere('is_advisor', true);
            })
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_advisor', 'firm_id']);

        return response()->json($this->mapAssignableUsers($advisors));
    }

    /**
     * List Website Admins for Network / Firm Admin deployment assignment.
     * Filtered server-side by the actor firm's Delegated compliance access
     * (same rules as firm create/update visibility).
     */
    public function websiteAdmins(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        if (
            ! $this->gate->can($user, 'wc_request_deployments')
            && ! $this->gate->can($user, 'wc_view_all_deployments')
            && ! $this->gate->can($user, 'wc_assign_website_templates')
            && ! $this->gate->can($user, 'wc_deploy_websites')
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $admins = $this->separation->usersHoldingRolesQuery(
            [User::ROLE_WEBSITE_ADMIN],
            ['id', 'name', 'email', 'role', 'firm_id']
        )->get();

        $user->loadMissing([
            'firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
        ]);

        // Power / FinProms with no firm see the full list; others are firm-scoped.
        if (! ($this->firmVisibility->actorBypassesFirmScope($user) && ! $user->firm)) {
            $admins = $this->firmVisibility->filterAssigneesForSubmitterFirm($admins, $user->firm);
        }

        return response()->json($this->mapAssignableUsers($admins));
    }

    /**
     * @param  Collection<int, User>  $users
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function mapAssignableUsers(Collection $users): Collection
    {
        return $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'roles' => $u->roleKeys(),
            'is_advisor' => (bool) $u->is_advisor,
            'firm_id' => $u->firm_id ? (int) $u->firm_id : null,
            'firm' => $u->firm ? [
                'id' => (int) $u->firm->id,
                'name' => $u->firm->name,
                'is_central' => (bool) $u->firm->is_central,
            ] : null,
        ])->values();
    }

    /**
     * Users whose role can review website change requests on the acting hub.
     */
    public function reviewers(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->gate->assertModuleEnabled($user);

        if (
            ! $this->gate->can($user, 'wc_assign_change_requests')
            && ! $this->gate->can($user, 'wc_view_all_change_requests')
            && ! $this->gate->can($user, 'wc_review_change_requests')
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $roles = array_values(array_filter(
            $this->matrix->allMatrixRoles(),
            fn (string $role) => $this->matrix->roleCan($hub, $role, 'wc_review_change_requests')
        ));

        if ($roles === []) {
            return response()->json([]);
        }

        $excludeOwnerId = null;
        $excludeOnBehalfId = null;
        $requestId = $request->integer('request_id');
        if ($requestId > 0) {
            $row = ChangeRequest::query()->find($requestId);
            if ($row) {
                $excludeOwnerId = $row->editor_id ? (int) $row->editor_id : null;
                $excludeOnBehalfId = $row->on_behalf_by_user_id ? (int) $row->on_behalf_by_user_id : null;
            }
        }

        $reviewers = $this->separation->excludeConflictedReviewers(
            $this->separation->usersHoldingRolesQuery($roles)->get(),
            $excludeOwnerId,
            $excludeOnBehalfId,
        );

        return response()->json(
            $reviewers->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'roles' => $u->roleKeys(),
                'firm_id' => $u->firm_id ? (int) $u->firm_id : null,
                'firm' => $u->firm ? [
                    'id' => (int) $u->firm->id,
                    'name' => $u->firm->name,
                    'is_central' => (bool) $u->firm->is_central,
                ] : null,
            ])->values()
        );
    }
}
