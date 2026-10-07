<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleBilling;
use App\Models\HubModuleRecurringBilling;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WebsiteCompliance\Template;
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
     * Used for recurring per-website charges.
     */
    public function countDeployedWebsites(Hub $hub): int
    {
        return (int) $this->onHubDatabase($hub, function () {
            return TemplateRequest::query()->where('status', 'deployed')->count();
        });
    }

    /**
     * Catalogue website templates on this hub (wc_templates) — used for WTL
     * one-time enable invoices (not template requests / deployments).
     */
    public function countLibraryTemplates(Hub $hub): int
    {
        return (int) $this->onHubDatabase($hub, function () {
            return Template::query()->count();
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, Template>
     */
    public function libraryTemplates(Hub $hub)
    {
        return $this->onHubDatabase($hub, function () {
            return Template::query()->orderBy('id')->get();
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
            // Do not fall back to Central's empty local WC tables — that silently
            // yields 0 websites and skips Shared / WL enable invoices.
            return $this->remoteDb->run($hub, function (string $connection) use ($callback, $hub) {
                return WcDatabaseContext::using($connection, fn () => $callback($connection), (int) $hub->id);
            });
        }

        return $callback(null);
    }

    /**
     * Deploy-time one-time invoicing is disabled — WTL one-time charges are
     * based on catalogue wc_templates at module enable, not template requests.
     */
    public function invoiceWebsiteDeploy(Hub $hub, TemplateRequest $templateRequest, User $actor): ?Invoice
    {
        return null;
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
     * Kept for recurring billing counts / tooling.
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
     * Create one-time invoices for modules that newly became enabled
     * (unchecked → checked). Always creates a fresh invoice for that enable.
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
            foreach ($this->invoiceModuleEnablement($hub, $moduleKey, $actor, forceNew: true) as $invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * Invoice every currently enabled module that has not been billed yet
     * (e.g. when charge_amount_per_module is turned on, or invoices page backfill).
     * Does NOT create a second invoice if an enable billing already exists.
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
            foreach ($this->invoiceModuleEnablement($hub, $moduleKey, $actor, forceNew: false) as $invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * @return list<Invoice>
     */
    public function invoiceModuleEnablement(
        Hub $hub,
        string $moduleKey,
        User $actor,
        bool $forceNew = false
    ): array {
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

        // Per-website modules: one consolidated invoice for currently deployed sites.
        if (($quote['billing_unit'] ?? ModulePricingService::BILLING_UNIT_ONE_TIME)
            === ModulePricingService::BILLING_UNIT_PER_WEBSITE
        ) {
            return $this->invoiceExistingDeployedWebsites($hub, $actor, $forceNew);
        }

        if (! $forceNew) {
            $existing = HubModuleBilling::query()
                ->where('hub_id', $hub->id)
                ->where('module_key', $moduleKey)
                ->where('status', '!=', HubModuleBilling::STATUS_CANCELED)
                ->get()
                ->first(function (HubModuleBilling $row) {
                    // Website deploy invoices are keyed separately; ignore them here.
                    return empty($row->meta['wc_template_request_id'])
                        && empty($row->meta['wc_template_request_ids']);
                });

            if ($existing) {
                if ($existing->invoice) {
                    return [];
                }

                return [$this->invoices->createForModuleBilling($existing)];
            }
        }

        // Flat one-time — including £0 catalogue prices (still create the invoice).
        // On re-enable (forceNew), supersede previous unpaid enable rows first.
        $payer = $this->resolvePayer($actor);
        $isComplimentary = (float) $quote['amount'] <= 0;
        $status = $isComplimentary
            ? HubModuleBilling::STATUS_PAID
            : HubModuleBilling::STATUS_UNPAID;

        $invoice = DB::transaction(function () use ($hub, $moduleKey, $quote, $payer, $status, $isComplimentary, $actor, $forceNew) {
            if ($forceNew) {
                $this->removePreviousEnableInvoicesForModule($hub, $moduleKey);
            }

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
                    'module_enable_invoice' => true,
                    'auto_paid_on_hub_enable' => $isComplimentary,
                ],
            ]);

            return $this->invoices->createForModuleBilling($billing);
        });

        return [$invoice];
    }

    /**
     * Create one WTL enable invoice (unit rate × catalogue wc_templates count).
     * Uncheck → check (forceNew) removes previous enable invoices and creates a new one.
     *
     * @return list<Invoice>
     */
    public function invoiceExistingDeployedWebsites(Hub $hub, User $actor, bool $forceNew = false): array
    {
        if (! $this->billingEnabled($hub) || ! $hub->moduleEffectivelyEnabled(self::WEBSITE_MODULE_KEY)) {
            return [];
        }

        if (! $forceNew && $this->existingWtlEnableBilling($hub)) {
            return [];
        }

        $templates = $this->libraryTemplates($hub);
        if ($templates->isEmpty()) {
            return [];
        }

        $quote = $this->pricing->quote(self::WEBSITE_MODULE_KEY);
        $unitAmount = (float) $quote['amount'];
        $count = $templates->count();
        $amount = round($unitAmount * $count, 2);
        $templateIds = $templates->map(fn (Template $t) => (int) $t->id)->all();
        $templateNames = $templates->map(fn (Template $t) => (string) ($t->name ?: $t->slug ?: 'template #'.$t->id))->all();

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
            $templateIds,
            $templateNames,
            $status,
            $isComplimentary,
            $actor,
            $forceNew
        ) {
            // Always remove prior WTL one-time rows before creating the enable invoice.
            $this->removePreviousEnableInvoicesForModule($hub, self::WEBSITE_MODULE_KEY);

            if (! $forceNew && $this->existingWtlEnableBilling($hub)) {
                return null;
            }

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
                    'wtl_enable_invoice' => true,
                    'module_enable_invoice' => true,
                    'billed_by' => 'wc_templates',
                    'website_count' => $count,
                    'template_count' => $count,
                    'unit_amount' => $unitAmount,
                    'wc_template_ids' => $templateIds,
                    'template_names' => $templateNames,
                    'domain_name' => $count === 1
                        ? ($templateNames[0] ?? '')
                        : sprintf('%d templates', $count),
                    'auto_paid_on_hub_enable' => $isComplimentary,
                ],
            ]);

            $invoice = $this->invoices->createForModuleBilling($billing);
            $invoice->forceFill([
                'description' => sprintf(
                    'Module (one time) — Website Template Library — %d template%s × £%s (%s)',
                    $count,
                    $count === 1 ? '' : 's',
                    number_format($unitAmount, 2),
                    $hub->name
                ),
                // Keep renew-day due (createForModuleBilling already sets this; reaffirm after description rewrite).
                'due_on' => $hub->nextModuleInvoiceDueDate()->toDateString(),
            ])->save();

            return $invoice->fresh();
        });

        return $invoice ? [$invoice] : [];
    }

    /**
     * Non-canceled consolidated / enable invoice for Website Template Library.
     */
    public function existingWtlEnableBilling(Hub $hub): ?HubModuleBilling
    {
        return HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', self::WEBSITE_MODULE_KEY)
            ->where('status', '!=', HubModuleBilling::STATUS_CANCELED)
            ->get()
            ->first(function (HubModuleBilling $row) {
                return ! empty($row->meta['consolidated_websites'])
                    || ! empty($row->meta['wtl_enable_invoice'])
                    || ! empty($row->meta['module_enable_invoice']);
            });
    }

    /**
     * Hard-delete previous one-time enable invoices for a module (and legacy
     * per-site WTL rows) so uncheck → check replaces them entirely.
     */
    public function removePreviousEnableInvoicesForModule(Hub $hub, string $moduleKey): int
    {
        $rows = HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', $moduleKey)
            ->with('invoice')
            ->get()
            ->filter(function (HubModuleBilling $row) use ($moduleKey) {
                // Always remove enable / consolidated rows.
                if (! empty($row->meta['module_enable_invoice'])
                    || ! empty($row->meta['wtl_enable_invoice'])
                    || ! empty($row->meta['consolidated_websites'])
                    || ! empty($row->meta['wc_template_ids'])
                    || ! empty($row->meta['wc_template_request_ids'])
                ) {
                    return true;
                }

                // Flat enable rows have no template-request id.
                if (empty($row->meta['wc_template_request_id'])) {
                    return true;
                }

                // Legacy per-site WTL deploy invoices — remove on WTL re-enable.
                return $moduleKey === self::WEBSITE_MODULE_KEY
                    && (int) ($row->meta['wc_template_request_id'] ?? 0) > 0;
            });

        $removed = 0;
        foreach ($rows as $billing) {
            if ($billing->invoice) {
                $billing->invoice->delete();
            }
            $billing->delete();
            $removed++;
        }

        return $removed;
    }

    /**
     * @deprecated Use removePreviousEnableInvoicesForModule()
     */
    public function cancelUnpaidEnableBillingsForModule(Hub $hub, string $moduleKey): int
    {
        return $this->removePreviousEnableInvoicesForModule($hub, $moduleKey);
    }

    /**
     * @deprecated Use removePreviousEnableInvoicesForModule()
     */
    public function cancelUnpaidLegacyPerSiteWtlBillings(Hub $hub): int
    {
        return $this->removePreviousEnableInvoicesForModule($hub, self::WEBSITE_MODULE_KEY);
    }

    /**
     * Existing non-canceled WTL billing that already covers this template request.
     * Deploy-time one-time invoicing is disabled; kept for older rows.
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
        $invoices = $this->invoiceModuleEnablement($hub, $moduleKey, $actor, forceNew: false);

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
