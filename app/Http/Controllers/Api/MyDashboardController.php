<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyDashboardController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    /**
     * Member personal dashboard: subscription, credits, invoices, purchases.
     * Sections are gated by General options (dashboard) capabilities.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->hubs->current();
        $role = (string) $user->role;

        if (! $this->matrix->roleHasGeneralDashboardAccess($hub, $role)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $sections = [];
        foreach (Hub::GENERAL_DASHBOARD_KEYS as $key) {
            $sections[$key] = $this->matrix->roleCan($hub, $role, $key);
        }

        $payload = [
            'sections' => $sections,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'role_label' => $user->role_label ?? null,
            ],
        ];

        if ($sections['general_show_credits']) {
            $payload['credits'] = [
                'balance' => (int) $user->credits,
                'has_unlimited_credits' => (bool) $user->has_unlimited_credits,
            ];
        }

        if ($sections['general_show_subscription']) {
            $activePlan = $user->activePlan();
            $subscriptions = $user->subscriptions()
                ->with('plan:id,name,price,credits,duration_days')
                ->latest()
                ->limit(10)
                ->get();

            $payload['subscription'] = [
                'active_plan' => $activePlan,
                'subscriptions' => $subscriptions,
            ];
        }

        if ($sections['general_show_invoices']) {
            $payload['invoices'] = $user->invoices()
                ->latest('issued_at')
                ->limit(8)
                ->get([
                    'id',
                    'invoice_number',
                    'type',
                    'description',
                    'amount',
                    'currency',
                    'credits',
                    'status',
                    'issued_at',
                ]);
        }

        if ($sections['general_show_purchases']) {
            $payload['purchases'] = $user->purchases()
                ->with(['post:id,title,type,category,credits_cost,attachment_path,attachment_name,attachment_mime'])
                ->latest('purchased_at')
                ->limit(8)
                ->get();
        }

        return response()->json($payload);
    }
}
