<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingAdvisorService;
use App\Services\CapabilitiesMatrixService;
use App\Services\CreditsReportService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyDashboardController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingAdvisorService $actingAdvisors,
        private readonly CreditsReportService $creditsReport
    ) {}

    /**
     * Member personal dashboard: subscription, credits, invoices, purchases.
     * Sections are gated by General options (dashboard) capabilities.
     * Admin-staff with an advisor selected see that advisor's billing data.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->hubs->current();
        $role = $this->matrix->effectiveRoleFor($user);

        if (! $this->matrix->roleHasGeneralDashboardAccess($hub, $role)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $subject = $this->actingAdvisors->billingSubject($user);
        $isActing = (int) $subject->id !== (int) $user->id;
        $hubUnlimited = $hub->can('unlimited_credits');

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
                'role_label' => $hub->roleLabel((string) $user->role),
            ],
            'billing_subject' => [
                'id' => $subject->id,
                'name' => $subject->name,
                'email' => $subject->email,
                'is_acting' => $isActing,
            ],
        ];

        if ($sections['general_show_credits']) {
            $payload['credits'] = [
                'balance' => (int) $subject->credits,
                'has_unlimited_credits' => $subject->hasUnlimitedCredits($hubUnlimited),
                'is_acting' => $isActing,
                'subject_name' => $subject->name,
            ];
        }

        if ($sections['general_show_subscription']) {
            $activePlan = $subject->activePlan();
            $subscriptions = $subject->subscriptions()
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
            $payload['invoices'] = $subject->invoices()
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
            $payload['purchases'] = $subject->purchases()
                ->with(['post:id,title,type,category,credits_cost,attachment_path,attachment_name,attachment_mime'])
                ->latest('purchased_at')
                ->limit(8)
                ->get();
        }

        return response()->json($payload);
    }

    /**
     * Full credits activity report for the billing subject (earned, spent, remaining, by day).
     */
    public function credits(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->hubs->current();
        $role = $this->matrix->effectiveRoleFor($user);

        if (! $this->matrix->roleCan($hub, $role, 'general_show_credits')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $subject = $this->actingAdvisors->billingSubject($user);
        $isActing = (int) $subject->id !== (int) $user->id;
        $hubUnlimited = $hub->can('unlimited_credits');

        return response()->json([
            'credits' => $this->creditsReport->forUser($subject, $hub, $hubUnlimited, $isActing),
        ]);
    }
}
