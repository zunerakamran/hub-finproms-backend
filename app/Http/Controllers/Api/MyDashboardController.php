<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\UserSubscription;
use App\Services\ActingAdvisorService;
use App\Services\CapabilitiesMatrixService;
use App\Services\CreditsReportService;
use App\Services\HubService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
            $activeSubscription = $subject->activeSubscription();
            $subscriptions = $subject->subscriptions()
                ->with('plan:id,name,price,credits,duration_days')
                ->latest()
                ->limit(25)
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => $row->id,
                        'status' => $row->status,
                        'payment_status' => $row->payment_status,
                        'credits_granted' => (int) $row->credits_granted,
                        'amount_paid' => $row->amount_paid,
                        'starts_at' => optional($row->starts_at)?->toIso8601String(),
                        'ends_at' => $this->subscriptionEndsAt($row)?->toIso8601String(),
                        'plan' => $row->plan ? [
                            'id' => $row->plan->id,
                            'name' => $row->plan->name,
                            'price' => $row->plan->price,
                            'credits' => $row->plan->credits,
                            'duration_days' => $row->plan->duration_days,
                        ] : null,
                    ];
                })
                ->values()
                ->all();

            $isPrivateHub = $hub->isWhiteLabel() || $hub->isPrivateInviteOnly();
            $allotment = null;
            if ($isPrivateHub) {
                $config = $hub->subscriberCreditsConfig();
                $allotment = [
                    'unlimited' => (bool) $config['unlimited'],
                    'credits' => $config['credits'],
                    'label' => $config['unlimited']
                        ? 'Unlimited credits'
                        : ((int) $config['credits']).' credits per subscriber period',
                ];
            }

            $payload['subscription'] = [
                'plans_enabled' => ! $hub->isWhiteLabel(),
                'is_private_hub' => $isPrivateHub,
                'is_white_label' => $hub->isWhiteLabel(),
                'allotment' => $allotment,
                'balance' => (int) $subject->credits,
                'has_unlimited_credits' => $subject->hasUnlimitedCredits($hubUnlimited),
                'active_plan' => $activeSubscription?->plan,
                'active_subscription' => $activeSubscription ? [
                    'id' => $activeSubscription->id,
                    'status' => $activeSubscription->status,
                    'credits_granted' => (int) $activeSubscription->credits_granted,
                    'starts_at' => optional($activeSubscription->starts_at)?->toIso8601String(),
                    'ends_at' => $this->subscriptionEndsAt($activeSubscription)?->toIso8601String(),
                    'plan' => $activeSubscription->plan ? [
                        'id' => $activeSubscription->plan->id,
                        'name' => $activeSubscription->plan->name,
                        'price' => $activeSubscription->plan->price,
                        'credits' => $activeSubscription->plan->credits,
                        'duration_days' => $activeSubscription->plan->duration_days,
                    ] : null,
                ] : null,
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

    /**
     * Prefer stored ends_at; for open-ended private allotments default to one month after start.
     */
    private function subscriptionEndsAt(UserSubscription $subscription): ?CarbonInterface
    {
        if ($subscription->ends_at) {
            return $subscription->ends_at;
        }

        $start = $subscription->starts_at ?? $subscription->created_at;
        if (! $start) {
            return null;
        }

        return Carbon::parse($start)->addMonth();
    }
}
