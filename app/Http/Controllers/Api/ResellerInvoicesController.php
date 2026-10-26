<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\Invoice;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModuleInvoiceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Network→user reseller invoices (when network_resells). Users see own under My invoices.
 */
class ResellerInvoicesController extends Controller
{
    public const VIEW_CAPABILITY = ModuleInvoiceSettingsService::VIEW_RESELLER_CAPABILITY;

    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ModuleInvoiceSettingsService $invoiceSettings
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->assertCanView($request);
        $actor = $request->user();
        $settings = $this->invoiceSettings->forHub($hub);

        if (! $settings->network_resells) {
            return response()->json([
                'data' => [],
                'message' => 'Network reseller mode is off. Enable it under Module invoice settings.',
                'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
            ]);
        }

        $invoices = Invoice::query()
            ->whereIn('type', [Invoice::TYPE_MODULE_BILLING, Invoice::TYPE_MODULE_RECURRING])
            ->where(function ($q) use ($hub) {
                $q->whereHas('moduleBilling', fn ($b) => $b->where('hub_id', $hub->id))
                    ->orWhereHas('moduleRecurringBilling', fn ($b) => $b->where('hub_id', $hub->id));
            })
            ->with([
                'moduleBilling',
                'moduleRecurringBilling',
                'user:id,name,email',
            ])
            ->latest('issued_at')
            ->get()
            ->filter(function (Invoice $invoice) use ($actor, $hub) {
                $billing = $invoice->type === Invoice::TYPE_MODULE_RECURRING
                    ? $invoice->moduleRecurringBilling
                    : $invoice->moduleBilling;
                if (! $billing) {
                    return false;
                }
                $meta = is_array($billing->meta ?? null) ? $billing->meta : [];

                return $this->invoiceSettings->viewerCanSeeResellerBilling(
                    $actor,
                    $hub,
                    (string) ($billing->payer_scope ?: 'user'),
                    $billing->billed_user_id !== null ? (int) $billing->billed_user_id : null,
                    $meta
                );
            })
            ->values();

        $perPage = max(1, (int) $request->integer('per_page', 20));
        $page = max(1, (int) $request->integer('page', 1));
        $total = $invoices->count();
        $slice = $invoices->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $slice,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) max(1, ceil($total / $perPage)),
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
            ],
        ]);
    }

    private function assertCanView(Request $request): Hub
    {
        $hub = $this->actingHubs->targetHub(
            $request->user(),
            $request->integer('hub_id') ?: null
        );
        $user = $request->user();
        if (! $user || ! $this->matrix->userCan($hub, $user, self::VIEW_CAPABILITY)) {
            abort(response()->json([
                'message' => 'Enable “See network→user reseller invoices” in Power Admin → Capabilities.',
            ], 403));
        }

        return $hub;
    }
}
