<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleBilling;
use App\Models\HubModuleRecurringBilling;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class ModuleBillingService
{
    public const WEBSITE_MODULE_KEY = 'module_website_template_library';

    public function __construct(
        private readonly ModulePricingService $pricing,
        private readonly InvoiceService $invoices,
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function billingEnabled(Hub $hub): bool
    {
        return $hub->can('charge_amount_per_module');
    }

    /**
     * Deployed showcase / advisor websites on this hub (wc_template_requests).
     * Uses the hub's own DB when remote wiring is configured.
     */
    public function countDeployedWebsites(Hub $hub): int
    {
        return (int) $this->onHubDatabase($hub, function () {
            return TemplateRequest::query()->where('status', 'deployed')->count();
        });
    }

    /**
     * Firms on this hub (for per_firm recurring). Uses remote DB when configured.
     */
    public function countFirms(Hub $hub): int
    {
        return (int) $this->onHubDatabase($hub, function (?string $connection) {
            if ($connection) {
                return (int) DB::connection($connection)->table('firms')->count();
            }

            return (int) \App\Models\Firm::query()->count();
        });
    }

    /**
     * Run a callback against the hub's WC/user database (local or remote).
     *
     * @template T
     *
     * @param  callable(?string): T  $callback  connection name or null for default
     * @return T
     */
    public function onHubDatabase(Hub $hub, callable $callback): mixed
    {
        if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
            try {
                return $this->remoteDb->run($hub, function (string $connection) use ($callback) {
                    return WcDatabaseContext::using($connection, fn () => $callback($connection));
                });
            } catch (\Throwable $e) {
                report($e);

                return $callback(null);
            }
        }

        return $callback(null);
    }

    /**
     * One-time £/website invoice when a WC template request is deployed.
     * Idempotent per hub + template_request id (also skips if already covered
     * by a consolidated enable invoice).
     */
    public function invoiceWebsiteDeploy(Hub $hub, TemplateRequest $templateRequest, User $actor): ?Invoice
    {
        if (! $this->billingEnabled($hub)) {
            return null;
        }

        if (! $hub->moduleEffectivelyEnabled(self::WEBSITE_MODULE_KEY)) {
            return null;
        }

        if ((string) $templateRequest->status !== 'deployed') {
            return null;
        }

        $requestId = (int) $templateRequest->id;
        $existing = $this->billingCoveringWebsiteRequest($hub, $requestId);

        if ($existing) {
            return $existing->invoice ?: $this->invoices->createForModuleBilling($existing);
        }

        $quote = $this->pricing->quote(self::WEBSITE_MODULE_KEY);
        // £0 catalogue prices still produce an invoice row (audit / settle trail).

        if (($quote['billing_unit'] ?? '') !== ModulePricingService::BILLING_UNIT_PER_WEBSITE) {
            // Misconfigured catalogue — still allow amount as one website charge.
        }

        $payer = $this->resolvePayer($actor);
        $domain = (string) ($templateRequest->domain_name ?: $templateRequest->cpanel_domain ?: 'website #'.$requestId);
        $isComplimentary = (float) $quote['amount'] <= 0;
        $status = $isComplimentary
            ? HubModuleBilling::STATUS_PAID
            : HubModuleBilling::STATUS_UNPAID;

        return DB::transaction(function () use ($hub, $quote, $payer, $requestId, $domain, $templateRequest, $status, $isComplimentary, $actor) {
            $billing = HubModuleBilling::query()->create([
                'hub_id' => $hub->id,
                'module_key' => self::WEBSITE_MODULE_KEY,
                'billed_user_id' => $payer->id,
                'amount' => $quote['amount'],
                'currency' => $quote['currency'],
                'status' => $status,
                'payment_status' => $status,
                'paid_at' => $isComplimentary ? now() : null,
                'paid_by_user_id' => $isComplimentary ? $actor->id : null,
                'payment_method' => $isComplimentary ? 'complimentary' : null,
                'payment_notes' => $isComplimentary ? '£0 catalogue price — included' : null,
                'meta' => [
                    'module_label' => Hub::CHECKLIST_DEFINITIONS[self::WEBSITE_MODULE_KEY]['label'] ?? self::WEBSITE_MODULE_KEY,
                    'billing_cadence' => Invoice::TYPES_ONE_TIME,
                    'billing_unit' => ModulePricingService::BILLING_UNIT_PER_WEBSITE,
                    'website_count' => 1,
                    'unit_amount' => $quote['amount'],
                    'wc_template_request_id' => $requestId,
                    'wc_template_request_ids' => [$requestId],
                    'domain_name' => $domain,
                    'domains' => [$domain],
                    'template_name' => $templateRequest->template_name,
                    'auto_paid_on_hub_enable' => $isComplimentary,
                ],
            ]);

            $invoice = $this->invoices->createForModuleBilling($billing);
            // Clarify description for website deploys.
            $invoice->forceFill([
                'description' => sprintf(
                    'Module (one time) — Website Template Library — %s (%s)',
                    $domain,
                    $hub->name
                ),
                'due_on' => now()->toDateString(),
            ])->save();

            return $invoice->fresh();
        });
    }

    /**
     * Prefer the actor when they are client admin; otherwise first client admin, else actor.
     */
    public function resolvePayer(User $actor): User
    {
        if (in_array($actor->role, [User::ROLE_CLIENT_ADMIN, 'admin'], true)) {
            return $actor;
        }

        $clientAdmin = User::query()
            ->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin'])
            ->orderBy('id')
            ->first();

        return $clientAdmin ?: $actor;
    }

    /**
     * Deployed WC template requests on this hub (local or remote DB).
     *
     * @return \Illuminate\Support\Collection<int, TemplateRequest>
     */
    public function deployedWebsiteRequests(Hub $hub)
    {
        return $this->onHubDatabase($hub, function () {
            return TemplateRequest::query()
                ->where('status', 'deployed')
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * Create one-time invoices for modules that newly became enabled.
     *
     * @param  list<string>  $newlyEnabledKeys
     * @return list<Invoice>
     */
    public function invoiceNewlyEnabledModules(Hub $hub, array $newlyEnabledKeys, User $actor): array
    {
        if (! $this->billingEnabled($hub) || $newlyEnabledKeys === []) {
            return [];
        }

        $created = [];
        foreach ($newlyEnabledKeys as $moduleKey) {
            foreach ($this->invoiceModuleEnablement($hub, $moduleKey, $actor) as $invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * Invoice every currently enabled module that has not been billed yet
     * (e.g. when charge_amount_per_module is turned on, or a new hub is created).
     *
     * @return list<Invoice>
     */
    public function invoiceEnabledModules(Hub $hub, User $actor): array
    {
        if (! $this->billingEnabled($hub)) {
            return [];
        }

        $created = [];
        foreach ($hub->enabledModuleKeys() as $moduleKey) {
            foreach ($this->invoiceModuleEnablement($hub, $moduleKey, $actor) as $invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * @return list<Invoice>
     */
    public function invoiceModuleEnablement(Hub $hub, string $moduleKey, User $actor): array
    {
        if (! $this->billingEnabled($hub)) {
            return [];
        }

        if (! in_array($moduleKey, Hub::MODULE_KEYS, true)) {
            return [];
        }

        if (! $hub->moduleEffectivelyEnabled($moduleKey)) {
            return [];
        }

        $quote = $this->pricing->quote($moduleKey);

        // Per-website modules: one consolidated invoice for currently deployed sites
        // (unit rate × count). Later individual deploys still bill via invoiceWebsiteDeploy().
        if (($quote['billing_unit'] ?? ModulePricingService::BILLING_UNIT_ONE_TIME)
            === ModulePricingService::BILLING_UNIT_PER_WEBSITE
        ) {
            return $this->invoiceExistingDeployedWebsites($hub, $actor);
        }

        $existing = HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', $moduleKey)
            ->where('status', '!=', HubModuleBilling::STATUS_CANCELED)
            ->get()
            ->first(function (HubModuleBilling $row) {
                // Website deploy invoices are keyed separately; ignore them here.
                return empty($row->meta['wc_template_request_id']);
            });

        if ($existing) {
            // Already billed — only create a missing invoice row, never re-report as new.
            if ($existing->invoice) {
                return [];
            }

            return [$this->invoices->createForModuleBilling($existing)];
        }

        // Flat one-time — including £0 catalogue prices (still create the invoice).
        $payer = $this->resolvePayer($actor);
        $isComplimentary = (float) $quote['amount'] <= 0;
        $status = $isComplimentary
            ? HubModuleBilling::STATUS_PAID
            : HubModuleBilling::STATUS_UNPAID;

        $invoice = DB::transaction(function () use ($hub, $moduleKey, $quote, $payer, $status, $isComplimentary, $actor) {
            $billing = HubModuleBilling::query()->create([
                'hub_id' => $hub->id,
                'module_key' => $moduleKey,
                'billed_user_id' => $payer->id,
                'amount' => $quote['amount'],
                'currency' => $quote['currency'],
                'status' => $status,
                'payment_status' => $status,
                'paid_at' => $isComplimentary ? now() : null,
                'paid_by_user_id' => $isComplimentary ? $actor->id : null,
                'payment_method' => $isComplimentary ? 'complimentary' : null,
                'payment_notes' => $isComplimentary ? '£0 catalogue price — included' : null,
                'meta' => [
                    'module_label' => Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey,
                    'billing_cadence' => Invoice::TYPES_ONE_TIME,
                    'billing_unit' => $quote['billing_unit'] ?? ModulePricingService::BILLING_UNIT_ONE_TIME,
                    'auto_paid_on_hub_enable' => $isComplimentary,
                ],
            ]);

            return $this->invoices->createForModuleBilling($billing);
        });

        return [$invoice];
    }

    /**
     * Bill currently deployed websites that are not yet covered by a WTL one-time
     * invoice — as a SINGLE consolidated invoice (unit rate × unbilled count).
     * Used when WTL is enabled / charge flag turns on with sites already live.
     *
     * @return list<Invoice>
     */
    public function invoiceExistingDeployedWebsites(Hub $hub, User $actor): array
    {
        if (! $this->billingEnabled($hub) || ! $hub->moduleEffectivelyEnabled(self::WEBSITE_MODULE_KEY)) {
            return [];
        }

        $unbilled = $this->deployedWebsiteRequests($hub)
            ->filter(fn (TemplateRequest $request) => $this->billingCoveringWebsiteRequest($hub, (int) $request->id) === null)
            ->values();

        if ($unbilled->isEmpty()) {
            return [];
        }

        $quote = $this->pricing->quote(self::WEBSITE_MODULE_KEY);
        $unitAmount = (float) $quote['amount'];
        $count = $unbilled->count();
        $amount = round($unitAmount * $count, 2);
        $requestIds = $unbilled->map(fn (TemplateRequest $r) => (int) $r->id)->all();
        $domains = $unbilled->map(function (TemplateRequest $r) {
            return (string) ($r->domain_name ?: $r->cpanel_domain ?: 'website #'.$r->id);
        })->all();

        $payer = $this->resolvePayer($actor);
        $isComplimentary = $amount <= 0;
        $status = $isComplimentary
            ? HubModuleBilling::STATUS_PAID
            : HubModuleBilling::STATUS_UNPAID;

        $invoice = DB::transaction(function () use (
            $hub,
            $quote,
            $payer,
            $amount,
            $unitAmount,
            $count,
            $requestIds,
            $domains,
            $status,
            $isComplimentary,
            $actor
        ) {
            $billing = HubModuleBilling::query()->create([
                'hub_id' => $hub->id,
                'module_key' => self::WEBSITE_MODULE_KEY,
                'billed_user_id' => $payer->id,
                'amount' => $amount,
                'currency' => $quote['currency'],
                'status' => $status,
                'payment_status' => $status,
                'paid_at' => $isComplimentary ? now() : null,
                'paid_by_user_id' => $isComplimentary ? $actor->id : null,
                'payment_method' => $isComplimentary ? 'complimentary' : null,
                'payment_notes' => $isComplimentary ? '£0 catalogue price — included' : null,
                'meta' => [
                    'module_label' => Hub::CHECKLIST_DEFINITIONS[self::WEBSITE_MODULE_KEY]['label'] ?? self::WEBSITE_MODULE_KEY,
                    'billing_cadence' => Invoice::TYPES_ONE_TIME,
                    'billing_unit' => ModulePricingService::BILLING_UNIT_PER_WEBSITE,
                    'consolidated_websites' => true,
                    'website_count' => $count,
                    'unit_amount' => $unitAmount,
                    'wc_template_request_ids' => $requestIds,
                    'domains' => $domains,
                    'domain_name' => $count === 1 ? ($domains[0] ?? '') : sprintf('%d websites', $count),
                    'auto_paid_on_hub_enable' => $isComplimentary,
                ],
            ]);

            $invoice = $this->invoices->createForModuleBilling($billing);
            $invoice->forceFill([
                'description' => sprintf(
                    'Module (one time) — Website Template Library — %d website%s × £%s (%s)',
                    $count,
                    $count === 1 ? '' : 's',
                    number_format($unitAmount, 2),
                    $hub->name
                ),
                'due_on' => now()->toDateString(),
            ])->save();

            return $invoice->fresh();
        });

        return [$invoice];
    }

    /**
     * Existing non-canceled WTL billing that already covers this template request.
     */
    public function billingCoveringWebsiteRequest(Hub $hub, int $requestId): ?HubModuleBilling
    {
        return HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', self::WEBSITE_MODULE_KEY)
            ->where('status', '!=', HubModuleBilling::STATUS_CANCELED)
            ->get()
            ->first(function (HubModuleBilling $row) use ($requestId) {
                if ((int) ($row->meta['wc_template_request_id'] ?? 0) === $requestId) {
                    return true;
                }

                $ids = array_map('intval', (array) ($row->meta['wc_template_request_ids'] ?? []));

                return in_array($requestId, $ids, true);
            });
    }

    public function invoiceModuleIfNeeded(Hub $hub, string $moduleKey, User $actor): ?Invoice
    {
        $invoices = $this->invoiceModuleEnablement($hub, $moduleKey, $actor);

        return $invoices[0] ?? null;
    }

    /**
     * Manually settle an unpaid module invoice (notes + optional attachment).
     *
     * @param  array{
     *   payment_method?: string|null,
     *   payment_reference?: string|null,
     *   payment_notes?: string|null
     * }  $data
     */
    public function markInvoicePaid(
        Invoice $invoice,
        User $actor,
        array $data,
        ?UploadedFile $attachment = null
    ): Invoice {
        if (! in_array($invoice->type, [Invoice::TYPE_MODULE_BILLING, Invoice::TYPE_MODULE_RECURRING], true)) {
            throw new InvalidArgumentException('Only module invoices can be marked paid with this action.');
        }

        if ($invoice->type === Invoice::TYPE_MODULE_RECURRING) {
            return $this->markRecurringInvoicePaid($invoice, $actor, $data, $attachment);
        }

        $invoice->loadMissing('moduleBilling');
        $billing = $invoice->moduleBilling;
        if (! $billing) {
            throw new RuntimeException('Module billing record is missing for this invoice.');
        }

        if ($billing->status === HubModuleBilling::STATUS_CANCELED) {
            throw new InvalidArgumentException('Canceled module billings cannot be marked paid.');
        }

        if ($invoice->status === 'paid' && $billing->status === HubModuleBilling::STATUS_PAID) {
            return $invoice->fresh()->load([
                'moduleBilling.hub',
                'moduleBilling.paidBy',
                'user',
            ]);
        }

        return DB::transaction(function () use ($invoice, $billing, $actor, $data, $attachment) {
            $attachmentPath = $billing->payment_attachment_path;
            $attachmentName = $billing->payment_attachment_name;
            $attachmentMime = $billing->payment_attachment_mime;

            if ($attachment) {
                if ($attachmentPath) {
                    Storage::disk('public')->delete($attachmentPath);
                }
                $attachmentPath = $attachment->store('module-invoice-payments', 'public');
                $attachmentName = $attachment->getClientOriginalName();
                $attachmentMime = $attachment->getClientMimeType();
            }

            $billing->forceFill([
                'status' => HubModuleBilling::STATUS_PAID,
                'payment_status' => 'paid',
                'paid_at' => now(),
                'paid_by_user_id' => $actor->id,
                'payment_method' => $data['payment_method'] ?? 'manual',
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_notes' => $data['payment_notes'] ?? null,
                'payment_attachment_path' => $attachmentPath,
                'payment_attachment_name' => $attachmentName,
                'payment_attachment_mime' => $attachmentMime,
            ])->save();

            $invoice->forceFill([
                'status' => 'paid',
            ])->save();

            return $invoice->fresh()->load([
                'moduleBilling.hub',
                'moduleBilling.paidBy',
                'user',
            ]);
        });
    }

    /**
     * @param  array{
     *   payment_method?: string|null,
     *   payment_reference?: string|null,
     *   payment_notes?: string|null
     * }  $data
     */
    private function markRecurringInvoicePaid(
        Invoice $invoice,
        User $actor,
        array $data,
        ?UploadedFile $attachment = null
    ): Invoice {
        $invoice->loadMissing('moduleRecurringBilling');
        $billing = $invoice->moduleRecurringBilling;
        if (! $billing) {
            throw new RuntimeException('Recurring module billing record is missing for this invoice.');
        }

        if ($billing->status === HubModuleRecurringBilling::STATUS_CANCELED) {
            throw new InvalidArgumentException('Canceled recurring billings cannot be marked paid.');
        }

        if ($invoice->status === 'paid' && $billing->status === HubModuleRecurringBilling::STATUS_PAID) {
            return $invoice->fresh()->load([
                'moduleRecurringBilling.hub',
                'moduleRecurringBilling.paidBy',
                'user',
            ]);
        }

        return DB::transaction(function () use ($invoice, $billing, $actor, $data) {
            $billing->forceFill([
                'status' => HubModuleRecurringBilling::STATUS_PAID,
                'payment_status' => 'paid',
                'paid_at' => now(),
                'paid_by_user_id' => $actor->id,
                'payment_method' => $data['payment_method'] ?? 'manual',
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_notes' => $data['payment_notes'] ?? null,
            ])->save();

            $invoice->forceFill([
                'status' => 'paid',
            ])->save();

            return $invoice->fresh()->load([
                'moduleRecurringBilling.hub',
                'moduleRecurringBilling.paidBy',
                'user',
            ]);
        });
    }

    /**
     * Diff before/after checklist maps for modules that newly became effectively enabled.
     *
     * @param  array<string, bool>  $beforeChecklist
     * @param  array<string, bool>  $afterChecklist
     * @return list<string>
     */
    public function newlyEnabledModuleKeys(Hub $hub, array $beforeChecklist, array $afterChecklist): array
    {
        $newly = [];
        foreach ($hub->moduleKeysForPage() as $key) {
            $wasOn = $this->effectivelyEnabledIn($hub, $beforeChecklist, $key);
            $isOn = $this->effectivelyEnabledIn($hub, $afterChecklist, $key);
            if (! $wasOn && $isOn) {
                $newly[] = $key;
            }
        }

        return $newly;
    }

    /**
     * @param  array<string, bool>  $checklist
     */
    private function effectivelyEnabledIn(Hub $hub, array $checklist, string $key): bool
    {
        if (! ($checklist[$key] ?? false)) {
            return false;
        }

        foreach ($hub->moduleDependenciesFor($key) as $dep) {
            if (! ($checklist[$dep] ?? false)) {
                return false;
            }
        }

        return true;
    }
}
