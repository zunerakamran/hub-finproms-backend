<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\Invoice;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hub-wide one-time invoices (Types = One time): post/bundle purchases + one-time module bills.
 * Gated by dashboard_view_one_time_invoices.
 */
class OneTimeInvoicesController extends Controller
{
    public const VIEW_CAPABILITY = 'dashboard_view_one_time_invoices';

    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->assertCanView($request);
        $user = $request->user();

        $query = Invoice::query()
            ->where('types', Invoice::TYPES_ONE_TIME)
            ->with([
                'user:id,name,email',
                'postPurchase.post:id,title,type',
                'bundlePurchase.bundle:id,name',
                'moduleBilling.hub:id,name,slug',
            ])
            ->latest('issued_at');

        $this->scopeToHub($query, $hub, $user);

        $invoices = $query->paginate((int) $request->integer('per_page', 50));

        return response()->json([
            ...$invoices->toArray(),
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
        ]);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $hub = $this->assertCanView($request);
        $user = $request->user();

        if ($invoice->types !== Invoice::TYPES_ONE_TIME) {
            return response()->json(['message' => 'Not a one-time invoice.'], 404);
        }

        if (! $this->invoiceBelongsToHub($invoice, $hub, $user)) {
            return response()->json(['message' => 'Invoice does not belong to this hub.'], 404);
        }

        $invoice->load([
            'user:id,name,email',
            'subscription.plan',
            'postPurchase.post',
            'bundlePurchase.bundle',
            'moduleBilling.hub:id,name,slug',
            'moduleBilling.paidBy:id,name,email',
        ]);

        return response()->json([
            'invoice' => $invoice,
            'payment' => $invoice->type === Invoice::TYPE_MODULE_BILLING
                ? $invoice->moduleBilling?->paymentDetailsForApi()
                : null,
            'billing_breakdown' => $invoice->type === Invoice::TYPE_MODULE_BILLING
                ? $invoice->moduleBilling?->billingBreakdownForApi()
                : null,
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Invoice>  $query
     */
    private function scopeToHub($query, Hub $hub, $user): void
    {
        // Content-hub instance (not Central acting remotely): all one-time rows on this DB.
        if ($hub->isContentHub() && ! $this->actingHubs->isActingRemotely($user)) {
            return;
        }

        // Central control plane: module one-time bills for this hub only.
        // Post/bundle purchases live on each content hub’s own database.
        $query->where('type', Invoice::TYPE_MODULE_BILLING)
            ->whereHas('moduleBilling', fn ($b) => $b->where('hub_id', $hub->id));
    }

    private function invoiceBelongsToHub(Invoice $invoice, Hub $hub, $user): bool
    {
        if ($hub->isContentHub() && ! $this->actingHubs->isActingRemotely($user)) {
            return true;
        }

        if ($invoice->type !== Invoice::TYPE_MODULE_BILLING) {
            return false;
        }

        $invoice->loadMissing('moduleBilling');

        return (int) ($invoice->moduleBilling?->hub_id ?? 0) === (int) $hub->id;
    }

    private function assertCanView(Request $request): Hub
    {
        $hub = $this->actingHubs->targetHub(
            $request->user(),
            $request->integer('hub_id') ?: null
        );
        $user = $request->user();
        $role = $user ? $this->matrix->effectiveRoleFor($user) : null;

        $allowed = $user && $role
            ? $this->matrix->roleCan($hub, $role, self::VIEW_CAPABILITY)
            : false;

        if (! $allowed) {
            abort(response()->json([
                'message' => 'Viewing one-time invoices is disabled for your role. Enable “View one-time invoices” in Power Admin → Capabilities.',
            ], 403));
        }

        return $hub;
    }
}
