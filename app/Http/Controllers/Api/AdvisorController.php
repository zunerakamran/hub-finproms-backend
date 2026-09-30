<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\AdvisorBillingService;
use App\Services\AdvisorImportService;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModuleRecurringBillingService;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdvisorController extends Controller
{
    public function __construct(
        private readonly AdvisorImportService $importService,
        private readonly AdvisorBillingService $billingService,
        private readonly ModuleRecurringBillingService $recurringBilling,
        private readonly ActingHubService $actingHubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->assertCanAccessAdvisors($request);
        $status = (string) $request->query('status', 'active');
        $perPage = (int) $request->integer('per_page', 50);

        if ($this->shouldUseRemote($request, $hub)) {
            $payload = $this->remoteDb->run($hub, function (string $connection) use ($status, $perPage) {
                $query = User::on($connection)->with('firm:id,name')->where('is_advisor', true);

                if ($status === 'discontinued') {
                    $query->where('is_discontinued', true);
                } elseif ($status !== 'all') {
                    $query->where('is_suspended', false)->where('is_discontinued', false);
                }

                return $query->orderBy('name')->paginate($perPage);
            });

            return response()->json($payload);
        }

        $query = User::query()->with('firm:id,name')->where('is_advisor', true);

        if ($status === 'discontinued') {
            $query->where('is_discontinued', true);
        } elseif ($status !== 'all') {
            $query->where('is_suspended', false)->where('is_discontinued', false);
        }

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function discontinue(Request $request, int $advisor): JsonResponse
    {
        $hub = $this->assertCanDiscontinue($request);

        if ($this->shouldUseRemote($request, $hub)) {
            try {
                $user = $this->remoteDb->run($hub, function (string $connection) use ($advisor) {
                    $model = User::on($connection)->find($advisor);
                    if (! $model) {
                        throw new HttpException(404, 'Advisor not found.');
                    }

                    return $this->importService->discontinue($model);
                });
            } catch (HttpException $e) {
                return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
            }

            return response()->json([
                'message' => sprintf('%s has been discontinued and can no longer access this hub.', $user->name),
                'advisor' => $user,
                'target_hub' => $this->hubPayload($hub),
            ]);
        }

        $model = User::query()->find($advisor);
        if (! $model) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (ActingHubService::isControlPlaneRole((string) $model->role)) {
            return response()->json([
                'message' => 'Control-plane admin accounts cannot be discontinued here.',
            ], 422);
        }

        if ($model->isDiscontinued()) {
            return response()->json([
                'message' => 'This user is already discontinued.',
                'advisor' => $model,
            ]);
        }

        $model = $this->importService->discontinue($model);

        return response()->json([
            'message' => sprintf('%s has been discontinued and can no longer access this hub.', $model->name),
            'advisor' => $model,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $hub = $this->assertImportEnabled($request);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension() ?: '');

        if (! in_array($extension, ['xlsx', 'xls'], true)) {
            return response()->json([
                'message' => 'Please upload an Excel file (.xlsx).',
            ], 422);
        }

        $useRemote = $this->shouldUseRemote($request, $hub);
        // Recurring module billing replaces legacy advisor-tier import payment gate.
        $recurringOn = $this->recurringBilling->billingEnabled($hub);
        $billingOn = ! $recurringOn && $this->billingService->billingEnabled($hub);

        try {
            if ($billingOn) {
                // Stage only — advisors are created when Pay now runs.
                if ($useRemote) {
                    $plan = $this->remoteDb->run($hub, function (string $connection) use ($file, $hub) {
                        return $this->importService->buildPlan($file, $hub, $connection);
                    });
                } else {
                    $plan = $this->importService->buildPlan($file, $hub);
                }

                $billable = (int) ($plan['summary']['billable_batch'] ?? 0);

                if ($billable < 1) {
                    // Updates / skips only — nothing to charge; apply immediately.
                    if ($useRemote) {
                        $result = $this->remoteDb->run($hub, function (string $connection) use ($plan, $hub) {
                            return $this->importService->commitPending(
                                $plan['pending'],
                                $hub,
                                $connection,
                                $plan['skipped']
                            );
                        });
                    } else {
                        $result = $this->importService->commitPending(
                            $plan['pending'],
                            $hub,
                            null,
                            $plan['skipped']
                        );
                    }

                    $quote = $this->billingService->quotePayload(null, $request->user(), $hub);
                    $quote['payment_required'] = false;
                    $quote['message'] = 'No new advisors were imported, so no payment is due.';

                    return response()->json([
                        'message' => sprintf(
                            'Import finished: %d created, %d updated, %d skipped.',
                            $result['summary']['created'],
                            $result['summary']['updated'],
                            $result['summary']['skipped']
                        ),
                        ...$result,
                        'awaiting_payment' => false,
                        'billing' => null,
                        'quote' => $quote,
                        'target_hub' => $this->hubPayload($hub),
                    ]);
                }

                $billing = null;
                $quote = null;
                try {
                    $billing = $this->billingService->createPendingAfterImport(
                        $request->user(),
                        $plan['summary'],
                        $hub,
                        $useRemote,
                        [
                            'rows' => $plan['pending'],
                            'skipped' => $plan['skipped'],
                            'use_remote' => $useRemote,
                        ]
                    );
                    $quote = $this->billingService->quotePayload($billing, $request->user(), $hub);

                    if (! $billing) {
                        $quote['payment_required'] = true;
                        $quote['error'] = $quote['error']
                            ?? 'Billing quote could not be created. Check advisor pricing tiers.';
                    } else {
                        $quote['payment_required'] = true;
                    }
                } catch (\Throwable $e) {
                    $quote = [
                        'billing_enabled' => true,
                        'payment_required' => true,
                        'error' => $e->getMessage(),
                        'payment_methods' => [],
                    ];
                }

                return response()->json([
                    'message' => sprintf(
                        'Import ready: %d new advisors will be created after you choose a payment method.',
                        $billable
                    ),
                    'created' => [],
                    'updated' => $plan['preview']['updated'] ?? [],
                    'reactivated' => [],
                    'skipped' => $plan['skipped'] ?? [],
                    'preview' => $plan['preview'] ?? [],
                    'summary' => $plan['summary'] ?? [],
                    'awaiting_payment' => (bool) $billing,
                    'billing' => $billing,
                    'quote' => $quote,
                    'target_hub' => $this->hubPayload($hub),
                ]);
            }

            // Billing off OR recurring module billing on — create users immediately.
            if ($useRemote) {
                $result = $this->remoteDb->run($hub, function (string $connection) use ($file, $hub) {
                    return $this->importService->import($file, $hub, $connection);
                });
            } else {
                $result = $this->importService->import($file, $hub);
            }
        } catch (InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $moduleInvoices = [];
        if ($recurringOn) {
            $importedUsers = $this->collectImportedUsersFromResult($result, $useRemote ? $hub : null);
            try {
                if ($useRemote) {
                    $moduleInvoices = $this->remoteDb->run($hub, function (string $connection) use ($hub, $importedUsers, $request) {
                        // Prefer connection-local models when IDs were returned from remote.
                        $users = \App\Models\User::on($connection)
                            ->whereIn('id', collect($importedUsers)->pluck('id')->filter()->all())
                            ->get()
                            ->all();

                        return $this->recurringBilling->invoiceImportBatch(
                            $hub,
                            $users,
                            $request->user(),
                            $connection
                        );
                    });
                } else {
                    $moduleInvoices = $this->recurringBilling->invoiceImportBatch(
                        $hub,
                        $importedUsers,
                        $request->user()
                    );
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'message' => sprintf(
                'Import finished: %d created, %d updated, %d skipped.',
                $result['summary']['created'],
                $result['summary']['updated'],
                $result['summary']['skipped']
            ),
            ...$result,
            'awaiting_payment' => false,
            'billing' => null,
            'module_recurring_invoices' => collect($moduleInvoices)->map(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'amount' => (float) $inv->amount,
                'status' => $inv->status,
                'due_on' => $inv->due_on,
                'description' => $inv->description,
                'types' => $inv->types,
            ])->values(),
            'quote' => [
                'billing_enabled' => $recurringOn,
                'payment_required' => false,
                'recurring_module_billing' => $recurringOn,
                'payment_methods' => [],
                'message' => $recurringOn
                    ? 'Recurring module invoices (if any) are due today and will be charged on the hub renew day.'
                    : null,
            ],
            'target_hub' => $this->hubPayload($hub),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<\App\Models\User>
     */
    private function collectImportedUsersFromResult(array $result, ?Hub $remoteHub = null): array
    {
        $emails = collect($result['created'] ?? [])
            ->merge($result['reactivated'] ?? [])
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($emails === []) {
            return [];
        }

        // Local commit returns user models occasionally; fall back to email lookup.
        $fromRows = collect($result['created'] ?? [])
            ->merge($result['reactivated'] ?? [])
            ->map(fn ($row) => $row['user'] ?? null)
            ->filter(fn ($u) => $u instanceof User)
            ->values()
            ->all();

        if ($fromRows !== []) {
            return $fromRows;
        }

        return User::query()->whereIn('email', $emails)->get()->all();
    }

    public function template(Request $request): StreamedResponse
    {
        $hub = $this->assertImportEnabled($request);

        try {
            if ($this->shouldUseRemote($request, $hub)) {
                $xlsx = $this->remoteDb->run($hub, function (string $connection) use ($hub) {
                    return $this->importService->templateXlsx($hub, $connection);
                });
            } else {
                $xlsx = $this->importService->templateXlsx($hub);
            }
        } catch (InvalidArgumentException|\RuntimeException $e) {
            abort(response()->json(['message' => $e->getMessage()], 422));
        }

        return response()->streamDownload(function () use ($xlsx) {
            echo $xlsx;
        }, 'advisor-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function assertImportEnabled(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);
        $this->assertRoleCapability(
            $request,
            $hub,
            'advisor_excel_import',
            'Advisor Excel import is disabled for your role on this hub. Enable it in Power Admin → Capabilities.'
        );

        return $hub;
    }

    private function assertCanDiscontinue(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);
        $this->assertRoleCapability(
            $request,
            $hub,
            'advisor_discontinue',
            'Discontinuing advisors is disabled for your role on this hub. Enable it in Power Admin → Capabilities.'
        );

        return $hub;
    }

    private function assertCanAccessAdvisors(Request $request): Hub
    {
        $hub = $this->assertPrivateHub($request);
        $user = $request->user();

        $canImport = $user
            ? $this->matrix->roleCan($hub, (string) $user->role, 'advisor_excel_import')
            : $hub->can('advisor_excel_import');
        $canDiscontinue = $user
            ? $this->matrix->roleCan($hub, (string) $user->role, 'advisor_discontinue')
            : $hub->can('advisor_discontinue');

        if (! $canImport && ! $canDiscontinue) {
            abort(response()->json([
                'message' => 'Advisor management is disabled for your role on this hub. Enable Import or Discontinue under Power Admin → Capabilities.',
            ], 403));
        }

        return $hub;
    }

    private function assertPrivateHub(Request $request): Hub
    {
        $hub = $this->targetHub($request);

        if (! $hub->can('private_invite_only')) {
            abort(response()->json([
                'message' => 'Advisor tools are only available while this hub is white-labelled (invite-only).',
            ], 403));
        }

        return $hub;
    }

    private function assertRoleCapability(Request $request, Hub $hub, string $capability, string $message): void
    {
        $user = $request->user();

        $allowed = $user
            ? $this->matrix->roleCan($hub, (string) $user->role, $capability)
            : $hub->can($capability);

        if (! $allowed) {
            abort(response()->json([
                'message' => $message,
                'capability' => $capability,
            ], 403));
        }
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }

    private function shouldUseRemote(Request $request, Hub $hub): bool
    {
        $user = $request->user();

        return $user
            && $hub->isWhiteLabel()
            && $this->actingHubs->isActingRemotely($user)
            && $hub->hasRemoteDatabaseConfigured();
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
        ];
    }
}
