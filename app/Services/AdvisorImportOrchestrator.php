<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Runs advisor Excel import off the HTTP thread (via ProcessAdvisorImportJob).
 *
 * @phpstan-type ImportPayload array<string, mixed>
 */
class AdvisorImportOrchestrator
{
    public function __construct(
        private readonly AdvisorImportService $importService,
        private readonly AdvisorBillingService $billingService,
        private readonly ModuleRecurringBillingService $recurringBilling,
        private readonly WhiteLabelDatabaseService $remoteDb,
    ) {}

    /**
     * @return ImportPayload
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function run(UploadedFile $file, Hub $hub, User $actor, bool $useRemote): array
    {
        $recurringOn = $this->recurringBilling->billingEnabled($hub);
        $billingOn = ! $recurringOn && $this->billingService->billingEnabled($hub);

        if ($billingOn) {
            return $this->runBillingStaging($file, $hub, $actor, $useRemote);
        }

        return $this->runImmediate($file, $hub, $actor, $useRemote, $recurringOn);
    }

    /**
     * @return ImportPayload
     */
    private function runBillingStaging(UploadedFile $file, Hub $hub, User $actor, bool $useRemote): array
    {
        if ($useRemote) {
            $plan = $this->remoteDb->run($hub, function (string $connection) use ($file, $hub) {
                return $this->importService->buildPlan($file, $hub, $connection);
            });
        } else {
            $plan = $this->importService->buildPlan($file, $hub);
        }

        $billable = (int) ($plan['summary']['billable_batch'] ?? 0);

        if ($billable < 1) {
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

            $quote = $this->billingService->quotePayload(null, $actor, $hub);
            $quote['payment_required'] = false;
            $quote['message'] = 'No new advisors were imported, so no payment is due.';

            return $this->jsonSafe([
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
                $actor,
                $plan['summary'],
                $hub,
                $useRemote,
                [
                    'rows' => $plan['pending'],
                    'skipped' => $plan['skipped'],
                    'use_remote' => $useRemote,
                ]
            );
            $quote = $this->billingService->quotePayload($billing, $actor, $hub);

            if (! $billing) {
                $quote['payment_required'] = true;
                $quote['error'] = $quote['error']
                    ?? 'Billing quote could not be created. Check advisor pricing tiers.';
            } else {
                $quote['payment_required'] = true;
            }
        } catch (Throwable $e) {
            $quote = [
                'billing_enabled' => true,
                'payment_required' => true,
                'error' => $e->getMessage(),
                'payment_methods' => [],
            ];
        }

        return $this->jsonSafe([
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

    /**
     * @return ImportPayload
     */
    private function runImmediate(
        UploadedFile $file,
        Hub $hub,
        User $actor,
        bool $useRemote,
        bool $recurringOn
    ): array {
        if ($useRemote) {
            $result = $this->remoteDb->run($hub, function (string $connection) use ($file, $hub) {
                return $this->importService->import($file, $hub, $connection);
            });
        } else {
            $result = $this->importService->import($file, $hub);
        }

        $moduleInvoices = [];
        if ($recurringOn) {
            $importedUsers = $this->collectImportedUsersFromResult($result, $useRemote ? $hub : null);
            try {
                if ($useRemote) {
                    $moduleInvoices = $this->remoteDb->run($hub, function (string $connection) use ($hub, $importedUsers, $actor) {
                        $users = User::on($connection)
                            ->whereIn('id', collect($importedUsers)->pluck('id')->filter()->all())
                            ->get()
                            ->all();

                        return $this->recurringBilling->invoiceImportBatch(
                            $hub,
                            $users,
                            $actor,
                            $connection
                        );
                    });
                } else {
                    $moduleInvoices = $this->recurringBilling->invoiceImportBatch(
                        $hub,
                        $importedUsers,
                        $actor
                    );
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->jsonSafe([
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
            ])->values()->all(),
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
     * @return list<User>
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

        $fromRows = collect($result['created'] ?? [])
            ->merge($result['reactivated'] ?? [])
            ->map(fn ($row) => $row['user'] ?? null)
            ->filter(fn ($u) => $u instanceof User)
            ->values()
            ->all();

        if ($fromRows !== []) {
            return $fromRows;
        }

        if ($remoteHub && $remoteHub->hasRemoteDatabaseConfigured()) {
            return $this->remoteDb->run($remoteHub, function (string $connection) use ($emails) {
                return User::on($connection)->whereIn('email', $emails)->get()->all();
            });
        }

        return User::query()->whereIn('email', $emails)->get()->all();
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => (int) $hub->id,
            'name' => (string) $hub->name,
            'slug' => (string) $hub->slug,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function jsonSafe(array $payload): array
    {
        return json_decode(json_encode($payload), true) ?? [];
    }
}
