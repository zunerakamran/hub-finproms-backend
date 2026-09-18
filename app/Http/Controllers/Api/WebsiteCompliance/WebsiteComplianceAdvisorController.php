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

        return response()->json(
            User::query()
                ->whereIn('role', $roles)
                ->where(function ($q) {
                    $q->where('is_suspended', false)->orWhereNull('is_suspended');
                })
                ->where(function ($q) {
                    $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role'])
        );
    }
}
