<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdvisorBillingService;
use App\Services\AdvisorImportService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvisorController extends Controller
{
    public function __construct(
        private readonly AdvisorImportService $importService,
        private readonly AdvisorBillingService $billingService,
        private readonly HubService $hubs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanAccessAdvisors($request);

        $status = (string) $request->query('status', 'active');

        $query = User::query()->where('is_advisor', true);

        if ($status === 'discontinued') {
            $query->where('is_discontinued', true);
        } elseif ($status === 'all') {
            // no extra filter
        } else {
            // active (default): exclude hub-suspended and discontinued
            $query->where('is_suspended', false)->where('is_discontinued', false);
        }

        $advisors = $query->orderBy('name')->paginate((int) $request->integer('per_page', 50));

        return response()->json($advisors);
    }

    public function discontinue(Request $request, User $advisor): JsonResponse
    {
        $this->assertCanDiscontinue($request);

        if (! $advisor->isAdvisor()) {
            return response()->json([
                'message' => 'Only imported advisors can be discontinued.',
            ], 422);
        }

        if ($advisor->isDiscontinued()) {
            return response()->json([
                'message' => 'This advisor is already discontinued.',
                'advisor' => $advisor,
            ]);
        }

        $advisor = $this->importService->discontinue($advisor);

        return response()->json([
            'message' => sprintf('%s has been discontinued and can no longer access this hub.', $advisor->name),
            'advisor' => $advisor,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $this->assertImportEnabled($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true)) {
            return response()->json([
                'message' => 'Please upload a CSV or Excel file (.xlsx).',
            ], 422);
        }

        $result = $this->importService->import($file);

        $billing = null;
        $quote = null;
        try {
            $billing = $this->billingService->createPendingAfterImport(
                $request->user(),
                $result['summary'] ?? []
            );
            $quote = $this->billingService->quotePayload($billing, $request->user());

            if (! $billing && $this->billingService->billingEnabled()) {
                $billable = (int) ($result['summary']['billable_batch'] ?? 0);
                if ($billable === 0) {
                    $quote['payment_required'] = false;
                    $quote['message'] = 'No new advisors were imported, so no payment is due.';
                } else {
                    $quote['payment_required'] = true;
                    $quote['error'] = $quote['error']
                        ?? 'Billing quote could not be created. Check advisor pricing tiers.';
                }
            } elseif ($billing) {
                $quote['payment_required'] = true;
            }
        } catch (\Throwable $e) {
            $quote = [
                'billing_enabled' => $this->billingService->billingEnabled(),
                'payment_required' => $this->billingService->billingEnabled(),
                'error' => $e->getMessage(),
                'payment_methods' => [],
            ];
        }

        return response()->json([
            'message' => sprintf(
                'Import finished: %d created, %d updated, %d skipped.',
                $result['summary']['created'],
                $result['summary']['updated'],
                $result['summary']['skipped']
            ),
            ...$result,
            'billing' => $billing,
            'quote' => $quote,
        ]);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->assertImportEnabled($request);

        $csv = $this->importService->templateCsv();

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'advisor-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function assertImportEnabled(Request $request): void
    {
        $this->assertPrivateHub();
        $this->assertRoleCapability($request, 'advisor_excel_import', 'Advisor Excel import is disabled for your role on this hub. Enable it in Power Admin → Capabilities.');
    }

    private function assertCanDiscontinue(Request $request): void
    {
        $this->assertPrivateHub();
        $this->assertRoleCapability($request, 'advisor_discontinue', 'Discontinuing advisors is disabled for your role on this hub. Enable it in Power Admin → Capabilities.');
    }

    private function assertCanAccessAdvisors(Request $request): void
    {
        $this->assertPrivateHub();

        $hub = $this->hubs->current();
        $user = $request->user();
        $matrix = app(\App\Services\CapabilitiesMatrixService::class);

        $canImport = $user
            ? $matrix->roleCan($hub, (string) $user->role, 'advisor_excel_import')
            : $hub->can('advisor_excel_import');
        $canDiscontinue = $user
            ? $matrix->roleCan($hub, (string) $user->role, 'advisor_discontinue')
            : $hub->can('advisor_discontinue');

        if (! $canImport && ! $canDiscontinue) {
            abort(response()->json([
                'message' => 'Advisor management is disabled for your role on this hub. Enable Import or Discontinue under Power Admin → Capabilities.',
            ], 403));
        }
    }

    private function assertPrivateHub(): void
    {
        if (! $this->hubs->current()->can('private_invite_only')) {
            abort(response()->json([
                'message' => 'Advisor tools are only available while this hub is private (invite-only).',
            ], 403));
        }
    }

    private function assertRoleCapability(Request $request, string $capability, string $message): void
    {
        $hub = $this->hubs->current();
        $user = $request->user();

        $allowed = $user
            ? app(\App\Services\CapabilitiesMatrixService::class)->roleCan($hub, (string) $user->role, $capability)
            : $hub->can($capability);

        if (! $allowed) {
            abort(response()->json([
                'message' => $message,
                'capability' => $capability,
            ], 403));
        }
    }
}
