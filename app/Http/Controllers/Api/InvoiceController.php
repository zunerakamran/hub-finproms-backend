<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\ActingAdvisorService;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        $hub = app(HubService::class)->current();
        $matrix = app(CapabilitiesMatrixService::class);
        $actingAdvisors = app(ActingAdvisorService::class);
        $role = $matrix->effectiveRoleFor($actor);

        if (! $matrix->roleCan($hub, $role, 'general_show_invoices')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $user = $actingAdvisors->billingSubject($actor);

        $invoices = $user
            ->invoices()
            ->whereIn('type', Invoice::PERSONAL_TYPES)
            ->with([
                'subscription.plan:id,name,credits,price',
                'postPurchase.post:id,title,type,categories,credits_cost',
            ])
            ->latest('issued_at')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();
        // Use acting/target hub so white-labelled private caps resolve correctly
        // (dashboard_view_advisor_invoices is private-only and false on shared current()).
        $hub = app(ActingHubService::class)->targetHub($user);
        $matrix = app(CapabilitiesMatrixService::class);
        $actingAdvisors = app(ActingAdvisorService::class);
        $role = $matrix->effectiveRoleFor($user);
        $subject = $actingAdvisors->billingSubject($user);

        $isOwner = (int) $invoice->user_id === (int) $user->id
            || (int) $invoice->user_id === (int) $subject->id;
        $canGeneralInvoices = $matrix->roleCan($hub, $role, 'general_show_invoices');
        $canAdvisorInvoices = $matrix->roleCan($hub, $role, 'dashboard_view_advisor_invoices');
        $canModuleInvoices = $matrix->roleCan($hub, $role, 'dashboard_view_module_invoices');

        if ($invoice->type === Invoice::TYPE_ADVISOR_BILLING) {
            $invoice->loadMissing('advisorBilling');
            $billingHubId = (int) ($invoice->advisorBilling?->hub_id ?? 0);
            $belongsToTargetHub = $billingHubId === 0 || $billingHubId === (int) $hub->id;
            $allowed = $isOwner || ($canAdvisorInvoices && $belongsToTargetHub);
        } elseif (in_array($invoice->type, [Invoice::TYPE_MODULE_BILLING, Invoice::TYPE_MODULE_RECURRING], true)) {
            $invoice->loadMissing('moduleBilling');
            $billingHubId = (int) ($invoice->moduleBilling?->hub_id ?? 0);
            $belongsToTargetHub = $billingHubId === 0 || $billingHubId === (int) $hub->id;
            $allowed = $canModuleInvoices && $belongsToTargetHub;
        } elseif (in_array($invoice->type, Invoice::PERSONAL_TYPES, true)) {
            // Personal invoices: owner (or acting subject) + general invoices capability.
            $allowed = $isOwner && $canGeneralInvoices;
        } else {
            $allowed = false;
        }

        if (! $allowed) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $invoice->load([
            'subscription.plan',
            'postPurchase.post',
            'bundlePurchase.bundle',
            'advisorBilling.hub:id,name,slug',
            'moduleBilling.hub:id,name,slug',
            'user:id,name,email',
        ]);

        return response()->json([
            'invoice' => $invoice,
        ]);
    }
}
