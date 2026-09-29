<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebsiteComplianceAdvisorController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    /**
     * List advisors for deployment assignment / request flows.
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

        return response()->json(
            User::query()
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
                ->get(['id', 'name', 'email', 'role', 'is_advisor'])
        );
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
            CapabilitiesMatrixService::MATRIX_ROLES,
            fn (string $role) => $this->matrix->roleCan($hub, $role, 'wc_review_change_requests')
        ));

        if ($roles === []) {
            return response()->json([]);
        }

        $reviewers = User::query()
            ->with('firm:id,name,is_central')
            ->whereIn('role', $roles)
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'firm_id']);

        return response()->json(
            $reviewers->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
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
