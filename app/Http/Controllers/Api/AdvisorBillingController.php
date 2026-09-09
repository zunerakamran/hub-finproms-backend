<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HubAdvisorBilling;
use App\Models\Invoice;
use App\Services\AdvisorBillingService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvisorBillingController extends Controller
{
    public function __construct(
        private readonly AdvisorBillingService $billing,
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    public function show(Request $request, HubAdvisorBilling $billing): JsonResponse
    {
        $this->assertCanManageBilling($request);
        $this->assertBillingHub($billing);

        return response()->json([
            'quote' => $this->billing->quotePayload($billing, $request->user()),
        ]);
    }

    public function renewalSettings(Request $request): JsonResponse
    {
        $this->assertCanManageRenewal($request);
        $hub = $this->hubs->current();

        return response()->json([
            'renew_day' => $hub->advisorBillingRenewDay(),
            'next_renewal_at' => $this->billing->nextRenewalAt($hub)->toIso8601String(),
            'has_stripe_subscription' => filled($hub->advisor_stripe_subscription_id),
        ]);
    }

    public function updateRenewalSettings(Request $request): JsonResponse
    {
        $this->assertCanManageRenewal($request);

        $validated = $request->validate([
            'renew_day' => ['required', 'integer', 'min:1', 'max:28'],
        ]);

        $hub = $this->hubs->current();
        $hub->advisor_billing_renew_day = (int) $validated['renew_day'];
        $hub->save();
        $this->hubs->forgetCurrentCache();

        return response()->json([
            'message' => 'Auto-renew day updated.',
            'renew_day' => $hub->advisorBillingRenewDay(),
            'next_renewal_at' => $this->billing->nextRenewalAt($hub->fresh())->toIso8601String(),
        ]);
    }

    public function checkout(Request $request, HubAdvisorBilling $billing): JsonResponse
    {
        $this->assertCanManageBilling($request);
        $this->assertBillingHub($billing);

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:stripe,bank_transfer,saved_card'],
        ]);

        try {
            $result = $this->billing->checkout(
                $billing,
                $request->user(),
                $validated['payment_method']
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = ! empty($result['charged_saved_card'])
            ? 'Saved card charged. Invoice created.'
            : 'Checkout started.';

        return response()->json([
            'message' => $message,
            ...$result,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->assertCanManageBilling($request);

        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $billing = $this->billing->fulfillStripeSession($validated['session_id']);

        if (! $billing) {
            return response()->json(['message' => 'Billing not found for this session.'], 404);
        }

        return response()->json([
            'message' => $billing->payment_status === 'paid'
                ? 'Advisor billing paid successfully. Card saved for future imports.'
                : 'Checkout recorded; payment still pending.',
            'billing' => $billing->load(['invoice', 'billedUser', 'hub']),
            'invoice' => $billing->invoice,
        ]);
    }

    public function confirmBankTransfer(Request $request, HubAdvisorBilling $billing): JsonResponse
    {
        $this->assertCanManageBilling($request);
        $this->assertBillingHub($billing);

        try {
            $billing = $this->billing->confirmBankTransfer($billing);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Bank transfer confirmed.',
            'billing' => $billing,
            'invoice' => $billing->invoice,
        ]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->assertCanViewAdvisorInvoices($request);

        $hub = $this->hubs->current();

        $invoices = Invoice::query()
            ->where('type', Invoice::TYPE_ADVISOR_BILLING)
            ->whereHas('advisorBilling', fn ($q) => $q->where('hub_id', $hub->id))
            ->with([
                'advisorBilling:id,hub_id,advisor_count,rate_per_advisor,amount,auto_renew,payment_method,period_starts_at,period_ends_at',
                'user:id,name,email',
            ])
            ->latest('issued_at')
            ->paginate((int) $request->integer('per_page', 20));

        return response()->json($invoices);
    }

    private function assertBillingHub(HubAdvisorBilling $billing): void
    {
        if ((int) $billing->hub_id !== (int) $this->hubs->current()->id) {
            abort(response()->json(['message' => 'Billing does not belong to this hub.'], 404));
        }
    }

    private function assertCanManageBilling(Request $request): void
    {
        $hub = $this->hubs->current();
        $user = $request->user();

        if (! $this->billing->billingEnabled($hub)) {
            abort(response()->json([
                'message' => 'Advisor subscriber billing is disabled for this hub.',
            ], 403));
        }

        $allowed = $user
            ? $this->matrix->roleCan($hub, (string) $user->role, 'advisor_excel_import')
            : false;

        if (! $allowed) {
            abort(response()->json([
                'message' => 'You do not have permission to manage advisor billing.',
            ], 403));
        }
    }

    private function assertCanManageRenewal(Request $request): void
    {
        $hub = $this->hubs->current();
        $user = $request->user();

        $allowed = $user
            ? $this->matrix->roleCan($hub, (string) $user->role, 'dashboard_manage_advisor_renewal')
            : false;

        if (! $allowed) {
            abort(response()->json([
                'message' => 'Setting the auto-renew day is disabled for your role. Enable it in Power Admin → Capabilities.',
            ], 403));
        }
    }

    private function assertCanViewAdvisorInvoices(Request $request): void
    {
        $hub = $this->hubs->current();
        $user = $request->user();

        $allowed = $user
            ? $this->matrix->roleCan($hub, (string) $user->role, 'dashboard_view_advisor_invoices')
            : false;

        if (! $allowed) {
            abort(response()->json([
                'message' => 'Viewing advisor billing invoices is disabled for your role. Enable it in Power Admin → Capabilities.',
            ], 403));
        }
    }
}
