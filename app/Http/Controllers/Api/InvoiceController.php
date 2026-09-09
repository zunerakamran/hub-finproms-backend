<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invoices = $request->user()
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
        $hub = app(\App\Services\HubService::class)->current();
        $matrix = app(\App\Services\CapabilitiesMatrixService::class);

        $isOwner = $invoice->user_id === $user->id;
        $canMemberInvoices = $matrix->roleCan($hub, (string) $user->role, 'member_view_invoices');
        $canAdvisorInvoices = $matrix->roleCan($hub, (string) $user->role, 'dashboard_view_advisor_invoices');

        // Staff must have the matching invoice capability — role alone is not enough.
        $allowed = $invoice->type === Invoice::TYPE_ADVISOR_BILLING
            ? ($isOwner || $canAdvisorInvoices)
            : (($isOwner && $canMemberInvoices) || ($user->isClientAdmin() && $canMemberInvoices));

        if (! $allowed) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $invoice->load([
            'subscription.plan',
            'postPurchase.post',
            'advisorBilling',
            'user:id,name,email',
        ]);

        return response()->json([
            'invoice' => $invoice,
        ]);
    }
}
