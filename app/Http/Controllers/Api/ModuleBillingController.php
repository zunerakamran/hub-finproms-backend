<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\Invoice;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModuleBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class ModuleBillingController extends Controller
{
    public const VIEW_CAPABILITY = 'dashboard_view_module_invoices';

    public const MARK_PAID_CAPABILITY = 'dashboard_mark_module_invoices_paid';

    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ModuleBillingService $moduleBilling
    ) {}

    public function invoices(Request $request): JsonResponse
    {
        $hub = $this->assertCanViewModuleInvoices($request);
        $actor = $request->user();

        // Backfill one-time invoices for hubs that already had modules / charging
        // enabled before this feature shipped (or defaults applied without a toggle).
        $created = [];
        if ($actor && $this->moduleBilling->billingEnabled($hub)) {
            $created = $this->moduleBilling->invoiceEnabledModules($hub, $actor);
        }

        $invoices = Invoice::query()
            ->where('type', Invoice::TYPE_MODULE_BILLING)
            ->whereHas('moduleBilling', fn ($q) => $q->where('hub_id', $hub->id))
            ->with([
                'moduleBilling.hub:id,name,slug',
                'moduleBilling.paidBy:id,name,email',
                'user:id,name,email',
            ])
            ->latest('issued_at')
            ->paginate((int) $request->integer('per_page', 20));

        $canMarkPaid = $actor
            ? $this->matrix->roleCan($hub, (string) $actor->role, self::MARK_PAID_CAPABILITY)
            : false;

        $payload = $invoices->toArray();
        $payload['data'] = collect($invoices->items())->map(function (Invoice $invoice) {
            $row = $invoice->toArray();
            $row['payment'] = $invoice->moduleBilling?->paymentDetailsForApi();

            return $row;
        })->all();

        return response()->json([
            ...$payload,
            'module_invoices_created' => collect($created)->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->amount,
                'description' => $invoice->description,
                'status' => $invoice->status,
            ])->all(),
            'can_mark_paid' => $canMarkPaid,
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'charge_amount_per_module' => $hub->can('charge_amount_per_module'),
            ],
        ]);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $hub = $this->assertCanViewModuleInvoices($request);
        $this->assertInvoiceBelongsToHub($invoice, $hub);

        $invoice->load([
            'moduleBilling.hub:id,name,slug',
            'moduleBilling.paidBy:id,name,email',
            'user:id,name,email',
        ]);

        $actor = $request->user();
        $canMarkPaid = $actor
            ? $this->matrix->roleCan($hub, (string) $actor->role, self::MARK_PAID_CAPABILITY)
            : false;

        return response()->json([
            'invoice' => $invoice,
            'payment' => $invoice->moduleBilling?->paymentDetailsForApi(),
            'can_mark_paid' => $canMarkPaid && $invoice->status !== 'paid',
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
            ],
        ]);
    }

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $hub = $this->assertCanMarkModuleInvoicesPaid($request);
        $this->assertInvoiceBelongsToHub($invoice, $hub);

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:manual,bank_transfer,stripe_offline,other'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_notes' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx'],
        ]);

        try {
            $updated = $this->moduleBilling->markInvoicePaid(
                $invoice,
                $request->user(),
                $validated,
                $request->file('attachment')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Module invoice marked as paid.',
            'invoice' => $updated,
            'payment' => $updated->moduleBilling?->paymentDetailsForApi(),
        ]);
    }

    private function assertInvoiceBelongsToHub(Invoice $invoice, Hub $hub): void
    {
        if ($invoice->type !== Invoice::TYPE_MODULE_BILLING) {
            abort(response()->json(['message' => 'Not a module invoice.'], 404));
        }

        $invoice->loadMissing('moduleBilling');
        if ((int) ($invoice->moduleBilling?->hub_id ?? 0) !== (int) $hub->id) {
            abort(response()->json(['message' => 'Invoice does not belong to this hub.'], 404));
        }
    }

    private function assertCanViewModuleInvoices(Request $request): Hub
    {
        return $this->assertCapability($request, self::VIEW_CAPABILITY, 'Viewing module invoices is disabled for your role. Enable “View module invoices” in Power Admin → Capabilities.');
    }

    private function assertCanMarkModuleInvoicesPaid(Request $request): Hub
    {
        return $this->assertCapability($request, self::MARK_PAID_CAPABILITY, 'Marking module invoices paid is disabled for your role. Enable “Mark module invoices as paid” in Power Admin → Capabilities.');
    }

    private function assertCapability(Request $request, string $capability, string $message): Hub
    {
        $hub = $this->actingHubs->targetHub(
            $request->user(),
            $request->integer('hub_id') ?: null
        );
        $user = $request->user();

        $allowed = $user
            ? $this->matrix->roleCan($hub, (string) $user->role, $capability)
            : false;

        if (! $allowed) {
            abort(response()->json(['message' => $message], 403));
        }

        return $hub;
    }
}
