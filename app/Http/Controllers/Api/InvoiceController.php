<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = app(HubService::class)->current();
        $matrix = app(CapabilitiesMatrixService::class);
        $role = (string) $user->role;

        if (! $matrix->roleCan($hub, $role, 'general_show_invoices')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $invoices = $user
            ->invoices()
            ->with([
                'subscription.plan:id,name,credits,price',
                'postPurchase.post:id,title,type,category,credits_cost',
            ])
            ->latest('issued_at')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();
        // Use acting/target hub so white-label private caps resolve correctly
        // (dashboard_view_advisor_invoices is private-only and false on shared current()).
        $hub = app(ActingHubService::class)->targetHub($user);
        $matrix = app(CapabilitiesMatrixService::class);
        $role = (string) $user->role;

        $isOwner = (int) $invoice->user_id === (int) $user->id;
        $canGeneralInvoices = $matrix->roleCan($hub, $role, 'general_show_invoices');
        $canAdvisorInvoices = $matrix->roleCan($hub, $role, 'dashboard_view_advisor_invoices');

        if ($invoice->type === Invoice::TYPE_ADVISOR_BILLING) {
            $invoice->loadMissing('advisorBilling');
            $billingHubId = (int) ($invoice->advisorBilling?->hub_id ?? 0);
            $belongsToTargetHub = $billingHubId === 0 || $billingHubId === (int) $hub->id;
            $allowed = $isOwner || ($canAdvisorInvoices && $belongsToTargetHub);
        } else {
            // Personal invoices: owner + general invoices capability.
            $allowed = $isOwner && $canGeneralInvoices;
        }

        if (! $allowed) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $invoice->load([
            'subscription.plan',
            'postPurchase.post',
            'bundlePurchase.bundle',
            'advisorBilling',
            'user:id,name,email',
        ]);

        return response()->json([
            'invoice' => $invoice,
        ]);
    }
}
