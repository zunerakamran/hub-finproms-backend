<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleBilling;
use App\Models\HubModuleRecurringBilling;
use App\Models\Invoice;
use App\Models\ModulePackage;
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
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly ModuleInvoiceSettingsService $invoiceSettings
    ) {}

    public function billingEnabled(Hub $hub): bool
    {
        return $hub->can('charge_amount_per_module');
    }

    /**
     * Deployed website template requests on this hub (wc_template_requests).
     * Used for one-time and recurring per_website charges — not the showcase catalogue.
     */
    public function countDeployedWebsites(Hub $hub): int
    {
        return (int) $this->onHubDatabase($hub, function () {
            return TemplateRequest::query()->where('status', 'deployed')->count();
        });
    }

    /**
     * Catalogue website templates on this hub (wc_templates) — showcase only.
     * Not used for per_website billing (see countDeployedWebsites).
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
     * Resolve one-time quantity + total for a catalogue unit rate.
     *
     * @param  list<string>|null  $accessModuleKeys  for per_user / slots (package: any of these)
     * @return array{unit: string, qty: int, unit_amount: float, amount: float, meta: array<string, mixed>}
     */
    public function quoteOneTimeQuantity(
        Hub $hub,
        string $billingUnit,
        float $unitAmount,
        ?string $moduleKey = null,
        ?array $accessModuleKeys = null,
        ?int $tierSlot = null
    ): array {
        $unit = ModulePricingService::normalizeUnit($billingUnit);
        $meta = ['billing_unit' => $billingUnit, 'normalized_billing_unit' => $unit];
        $qty = 1;
        $rate = $unitAmount;

        if ($unit === ModulePricingService::UNIT_PER_FIRM) {
            $qty = max(0, $this->countFirms($hub));
        } elseif ($unit === ModulePricingService::UNIT_PER_WEBSITE) {
            $qty = max(0, $this->countDeployedWebsites($hub));
            $requests = $this->deployedWebsiteRequests($hub);
            $meta['billed_by'] = 'wc_template_requests';
            $meta['website_count'] = $qty;
            $meta['wc_template_request_ids'] = $requests->map(fn (TemplateRequest $r) => (int) $r->id)->all();
            $meta['template_names'] = $requests->map(
                fn (TemplateRequest $r) => (string) ($r->domain_name ?: $r->template_name ?: 'request #'.$r->id)
            )->all();
            $meta['domain_name'] = $qty === 1
                ? (string) ($meta['template_names'][0] ?? '')
                : sprintf('%d websites', $qty);
            $meta['consolidated_websites'] = true;
        } elseif ($unit === ModulePricingService::UNIT_PER_USER) {
            $keys = $accessModuleKeys ?? ($moduleKey ? [$moduleKey] : []);
            $recurring = app(ModuleRecurringBillingService::class);
            $qty = max(0, count($recurring->activeUsersWithAnyModule($hub, $keys)));
        } elseif ($unit === ModulePricingService::UNIT_PER_USER_SLOTS) {
            $keys = $accessModuleKeys ?? ($moduleKey ? [$moduleKey] : []);
            $recurring = app(ModuleRecurringBillingService::class);
            $qty = max(0, count($recurring->activeUsersWithAnyModule($hub, $keys)));
            $slot = $tierSlot !== null && $tierSlot >= 1 ? $tierSlot : 0;
            $meta['recurring_tier_slot'] = $slot > 0 ? $slot : null;
            if ($slot >= 1) {
                $tier = $recurring->resolveTier($slot, $qty);
                $rate = $tier ? (float) $tier->rate_per_user : 0.0;
                $meta['tier_id'] = $tier?->id;
                $meta['rate_from_slot'] = true;
            }
        }
        // per_network (and legacy one_time): qty stays 1

        $amount = round($rate * max(0, $qty), 2);

        return [
            'unit' => $unit,
            'qty' => $qty,
            'unit_amount' => $rate,
            'amount' => $amount,
            'meta' => array_merge($meta, [
                'unit_amount' => $rate,
                'quantity' => $qty,
            ]),
        ];
    }

    /**
     * Firms on this hub (for per_firm recurring). Uses remote DB when configured.
     */
    public function countFirms(Hub $hub): int
    {
        return $this->listFirms($hub)->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Firm>
     */
    public function listFirms(Hub $hub)
    {
        return $this->onHubDatabase($hub, function (?string $connection) {
            if ($connection) {
                $rows = DB::connection($connection)->table('firms')->orderBy('id')->get();

                return $rows->map(function ($row) {
                    $firm = new \App\Models\Firm;
                    $firm->forceFill((array) $row);
                    $firm->id = (int) $row->id;
                    $firm->exists = true;

                    return $firm;
                });
            }

            return \App\Models\Firm::query()->orderBy('id')->get();
        });
    }

    /**
     * Expand a catalogue quote into one or more invoice targets based on
     * hub module invoice settings (network / firm / user pays).
     *
     * @param  list<string>|null  $accessModuleKeys
     * @return list<array{payer: User, amount: float, payer_scope: string, firm_id: ?int, meta: array<string, mixed>}>
     */
    public function expandBillingTargets(
        Hub $hub,
        string $billingUnit,
        float $unitAmount,
        float $consolidatedAmount,
        User $actor,
        ?string $moduleKey = null,
        ?array $accessModuleKeys = null,
        ?int $tierSlot = null
    ): array {
        $settings = $this->invoiceSettings->forHub($hub);
        $scope = $this->invoiceSettings->payerScopeForUnit($settings, $billingUnit);
        $branding = $this->invoiceSettings->resellerBrandingMeta($settings);
        $normalized = ModulePricingService::normalizeUnit($billingUnit);

        if ($scope === ModuleInvoiceSettingsService::PAYER_FIRM) {
            $firms = $this->listFirms($hub);
            $nonCentralCount = $firms->filter(fn ($f) => ! $f->isCentral())->count();
            $targets = [];
            foreach ($firms as $firm) {
                $firmId = (int) $firm->id;
                if ($firmId <= 0) {
                    continue;
                }
                // Skip central/network firm when member firms exist.
                if ($firm->isCentral() && $nonCentralCount > 0) {
                    continue;
                }
                $targets[] = [
                    'payer' => $this->invoiceSettings->resolveFirmPayer($hub, $firmId, $actor),
                    'amount' => round($unitAmount, 2),
                    'payer_scope' => ModuleInvoiceSettingsService::PAYER_FIRM,
                    'firm_id' => $firmId,
                    'meta' => array_merge($branding, [
                        'firm_id' => $firmId,
                        'firm_name' => (string) ($firm->name ?? ''),
                        'payer_scope' => ModuleInvoiceSettingsService::PAYER_FIRM,
                    ]),
                ];
            }

            return $targets;
        }

        if ($scope === ModuleInvoiceSettingsService::PAYER_USER) {
            $keys = $accessModuleKeys ?? ($moduleKey ? [$moduleKey] : []);
            $recurring = app(ModuleRecurringBillingService::class);
            $users = $recurring->activeUsersWithAnyModule($hub, $keys);
            $rate = $unitAmount;
            if ($normalized === ModulePricingService::UNIT_PER_USER_SLOTS) {
                $slot = $tierSlot !== null && $tierSlot >= 1 ? $tierSlot : 0;
                if ($slot >= 1) {
                    $tier = $recurring->resolveTier($slot, count($users));
                    $rate = $tier ? (float) $tier->rate_per_user : 0.0;
                }
            }
            $targets = [];
            foreach ($users as $user) {
                $targets[] = [
                    'payer' => $user,
                    'amount' => round($rate, 2),
                    'payer_scope' => ModuleInvoiceSettingsService::PAYER_USER,
                    'firm_id' => $user->firm_id ? (int) $user->firm_id : null,
                    'meta' => array_merge($branding, [
                        'payer_scope' => ModuleInvoiceSettingsService::PAYER_USER,
                        'billed_user_email' => $user->email,
                    ]),
                ];
            }

            return $targets;
        }

        $payer = $this->invoiceSettings->resolveNetworkPayer($hub, $actor);

        return [[
            'payer' => $payer,
            'amount' => round($consolidatedAmount, 2),
            'payer_scope' => ModuleInvoiceSettingsService::PAYER_NETWORK,
            'firm_id' => null,
            'meta' => array_merge($branding, [
                'payer_scope' => ModuleInvoiceSettingsService::PAYER_NETWORK,
            ]),
        ]];
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
     * One-time per_website charge when a template request is deployed.
     * Idempotent per request; skipped when WTL is covered by an enabled package
     * or catalogue billing_unit is not per_website.
     */
    public function invoiceWebsiteDeploy(Hub $hub, TemplateRequest $templateRequest, User $actor): ?Invoice
    {
        if (! $this->billingEnabled($hub) || ! $hub->moduleEffectivelyEnabled(self::WEBSITE_MODULE_KEY)) {
            return null;
        }
        if ($hub->moduleCoveredByEnabledPackage(self::WEBSITE_MODULE_KEY)) {
            return null;
        }

        $quote = $this->pricing->quote(self::WEBSITE_MODULE_KEY);
        $unit = ModulePricingService::normalizeUnit(
            (string) ($quote['billing_unit'] ?? ModulePricingService::UNIT_PER_NETWORK)
        );
        if ($unit !== ModulePricingService::UNIT_PER_WEBSITE) {
            return null;
        }

        $requestId = (int) $templateRequest->id;
        if ($requestId > 0 && $this->billingCoveringWebsiteRequest($hub, $requestId)) {
            return null;
        }

        $unitAmount = (float) $quote['amount'];
        $payer = $this->resolvePayer($actor);
        $isComplimentary = $unitAmount <= 0;
        $status = $isComplimentary
            ? HubModuleBilling::STATUS_PAID
            : HubModuleBilling::STATUS_UNPAID;
        $domain = (string) ($templateRequest->domain_name ?: $templateRequest->cpanel_domain ?: '');

        return DB::transaction(function () use (
            $hub,
            $quote,
            $payer,
            $unitAmount,
            $status,
            $isComplimentary,
            $actor,
            $templateRequest,
            $requestId,
            $domain
        ) {
            $billing = HubModuleBilling::query()->create([
                'hub_id' => $hub->id,
                'module_key' => self::WEBSITE_MODULE_KEY,
                'billed_user_id' => $payer->id,
                'amount' => $unitAmount,
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
                    'billing_unit' => ModulePricingService::UNIT_PER_WEBSITE,
                    'billed_by' => 'wc_template_requests',
                    'wc_template_request_id' => $requestId,
                    'website_count' => 1,
                    'unit_amount' => $unitAmount,
                    'domain_name' => $domain,
                    'auto_paid_on_hub_enable' => $isComplimentary,
                ],
            ]);

            $invoice = $this->invoices->createForModuleBilling($billing);
            $invoice->forceFill([
                'description' => sprintf(
                    'Module (one time) — Website Template Library — website request #%d%s (%s)',
                    $requestId,
                    $domain !== '' ? ' ('.$domain.')' : '',
                    $hub->name
                ),
                'due_on' => $hub->nextModuleInvoiceDueDate()->toDateString(),
            ])->save();

            return $invoice->fresh();
        });
    }

    /**
     * Network payer of record for module invoices (respects invoice settings).
     */
    public function resolvePayer(User $actor, ?Hub $hub = null): User
    {
        if ($hub) {
            return $this->invoiceSettings->resolveNetworkPayer($hub, $actor);
        }

        if (in_array($actor->role, [User::ROLE_CLIENT_ADMIN, 'admin', User::ROLE_NETWORK_ADMIN], true)) {
            return $actor;
        }

        $clientAdmin = User::query()
            ->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin', User::ROLE_NETWORK_ADMIN])
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
     * Modules covered by an enabled package are skipped (package invoice covers them).
     *
     * @param  list<string>  $newlyEnabledKeys
     * @return list<Invoice>
     */
    public function invoiceNewlyEnabledModules(Hub $hub, array $newlyEnabledKeys, User $actor): array
    {
        if (! $this->billingEnabled($hub) || $newlyEnabledKeys === []) {
            return [];
        }

        $covered = array_fill_keys($hub->moduleKeysCoveredByEnabledPackages(), true);
        $created = [];
        foreach ($newlyEnabledKeys as $moduleKey) {
            if (isset($covered[$moduleKey])) {
                continue;
            }
            foreach ($this->invoiceModuleEnablement($hub, $moduleKey, $actor, forceNew: true) as $invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * One-time invoices for newly enabled module packages.
     *
     * @param  list<int>  $newlyEnabledPackageIds
     * @return list<Invoice>
     */
    public function invoiceNewlyEnabledPackages(Hub $hub, array $newlyEnabledPackageIds, User $actor): array
    {
        if (! $this->billingEnabled($hub) || $newlyEnabledPackageIds === []) {
            return [];
        }

        $created = [];
        foreach ($newlyEnabledPackageIds as $packageId) {
            foreach ($this->invoicePackageEnablement($hub, (int) $packageId, $actor, forceNew: true) as $invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * @return list<Invoice>
     */
    public function invoicePackageEnablement(
        Hub $hub,
        int $packageId,
        User $actor,
        bool $forceNew = false
    ): array {
        if (! $this->billingEnabled($hub) || $packageId <= 0) {
            return [];
        }

        if (! in_array($packageId, $hub->enabledModulePackageIds(), true)) {
            return [];
        }

        $package = ModulePackage::query()->with('items')->find($packageId);
        if (! $package || ! $package->is_active) {
            return [];
        }

        $billingKey = $package->billingModuleKey();

        if (! $forceNew) {
            $existing = HubModuleBilling::query()
                ->where('hub_id', $hub->id)
                ->where('module_key', $billingKey)
                ->where('status', '!=', HubModuleBilling::STATUS_CANCELED)
                ->get()
                ->first(fn (HubModuleBilling $row) => ! empty($row->meta['module_package_enable_invoice']));

            if ($existing) {
                if ($existing->invoice) {
                    return [];
                }

                return [$this->invoices->createForModuleBilling($existing)];
            }
        }

        $moduleKeys = $package->moduleKeys();
        $billingUnit = (string) ($package->billing_unit ?: ModulePricingService::UNIT_PER_NETWORK);
        $tierSlot = $package->recurring_tier_slot !== null ? (int) $package->recurring_tier_slot : null;
        $priced = $this->quoteOneTimeQuantity(
            $hub,
            $billingUnit,
            (float) $package->amount,
            null,
            $moduleKeys,
            $tierSlot
        );
        $targets = $this->expandBillingTargets(
            $hub,
            $billingUnit,
            (float) $priced['unit_amount'],
            (float) $priced['amount'],
            $actor,
            null,
            $moduleKeys,
            $tierSlot
        );
        if ($targets === []) {
            return [];
        }

        return DB::transaction(function () use (
            $hub,
            $package,
            $billingKey,
            $billingUnit,
            $priced,
            $targets,
            $actor,
            $forceNew,
            $moduleKeys
        ) {
            if ($forceNew) {
                $this->removePreviousEnableInvoicesForModule($hub, $billingKey);
                foreach ($moduleKeys as $moduleKey) {
                    $this->removePreviousEnableInvoicesForModule($hub, $moduleKey);
                }
            }

            $created = [];
            foreach ($targets as $target) {
                $amount = (float) $target['amount'];
                $isComplimentary = $amount <= 0;
                $status = $isComplimentary
                    ? HubModuleBilling::STATUS_PAID
                    : HubModuleBilling::STATUS_UNPAID;
                /** @var User $payer */
                $payer = $target['payer'];

                $billing = HubModuleBilling::query()->create([
                    'hub_id' => $hub->id,
                    'module_key' => $billingKey,
                    'billed_user_id' => $payer->id,
                    'payer_scope' => $target['payer_scope'],
                    'firm_id' => $target['firm_id'],
                    'amount' => $amount,
                    'currency' => $package->currency ?: 'gbp',
                    'status' => $status,
                    'payment_status' => $status,
                    'paid_at' => $isComplimentary ? now() : null,
                    'paid_by_user_id' => $isComplimentary ? $actor->id : null,
                    'payment_method' => $isComplimentary ? 'complimentary' : null,
                    'payment_notes' => $isComplimentary ? '£0 package price — included' : null,
                    'meta' => array_merge([
                        'module_label' => $package->name,
                        'module_package_id' => $package->id,
                        'module_package_slug' => $package->slug,
                        'module_package_module_keys' => $moduleKeys,
                        'billing_cadence' => Invoice::TYPES_ONE_TIME,
                        'billing_unit' => $billingUnit,
                        'module_enable_invoice' => true,
                        'module_package_enable_invoice' => true,
                        'auto_paid_on_hub_enable' => $isComplimentary,
                    ], $priced['meta'], $target['meta']),
                ]);

                $created[] = $this->invoices->createForModuleBilling($billing);
            }

            return $created;
        });
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
        foreach ($hub->enabledModulePackageIds() as $packageId) {
            foreach ($this->invoicePackageEnablement($hub, (int) $packageId, $actor, forceNew: false) as $invoice) {
                $created[] = $invoice;
            }
        }
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

        // Covered by an enabled package — charged via package invoice only.
        if ($hub->moduleCoveredByEnabledPackage($moduleKey)) {
            return [];
        }

        $quote = $this->pricing->quote($moduleKey);
        $billingUnit = (string) ($quote['billing_unit'] ?? ModulePricingService::UNIT_PER_NETWORK);
        $normalized = ModulePricingService::normalizeUnit($billingUnit);

        // Per-website: consolidated invoice for currently deployed template requests.
        if ($normalized === ModulePricingService::UNIT_PER_WEBSITE) {
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

        $tierSlot = $quote['pricing']?->recurring_tier_slot !== null
            ? (int) $quote['pricing']->recurring_tier_slot
            : null;
        $priced = $this->quoteOneTimeQuantity(
            $hub,
            $billingUnit,
            (float) $quote['amount'],
            $moduleKey,
            [$moduleKey],
            $tierSlot
        );

        $targets = $this->expandBillingTargets(
            $hub,
            $billingUnit,
            (float) $priced['unit_amount'],
            (float) $priced['amount'],
            $actor,
            $moduleKey,
            [$moduleKey],
            $tierSlot
        );

        if ($targets === []) {
            return [];
        }

        return DB::transaction(function () use (
            $hub,
            $moduleKey,
            $quote,
            $priced,
            $billingUnit,
            $targets,
            $actor,
            $forceNew
        ) {
            if ($forceNew) {
                $this->removePreviousEnableInvoicesForModule($hub, $moduleKey);
            }

            $created = [];
            foreach ($targets as $target) {
                $amount = (float) $target['amount'];
                $isComplimentary = $amount <= 0;
                $status = $isComplimentary
                    ? HubModuleBilling::STATUS_PAID
                    : HubModuleBilling::STATUS_UNPAID;
                /** @var User $payer */
                $payer = $target['payer'];

                $billing = HubModuleBilling::query()->create([
                    'hub_id' => $hub->id,
                    'module_key' => $moduleKey,
                    'billed_user_id' => $payer->id,
                    'payer_scope' => $target['payer_scope'],
                    'firm_id' => $target['firm_id'],
                    'amount' => $amount,
                    'currency' => $quote['currency'],
                    'status' => $status,
                    'payment_status' => $status,
                    'paid_at' => $isComplimentary ? now() : null,
                    'paid_by_user_id' => $isComplimentary ? $actor->id : null,
                    'payment_method' => $isComplimentary ? 'complimentary' : null,
                    'payment_notes' => $isComplimentary ? '£0 catalogue price — included' : null,
                    'meta' => array_merge([
                        'module_label' => Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey,
                        'billing_cadence' => Invoice::TYPES_ONE_TIME,
                        'billing_unit' => $billingUnit,
                        'module_enable_invoice' => true,
                        'auto_paid_on_hub_enable' => $isComplimentary,
                    ], $priced['meta'], $target['meta']),
                ]);

                $created[] = $this->invoices->createForModuleBilling($billing);
            }

            return $created;
        });
    }

    /**
     * Create one WTL enable invoice (unit rate × deployed template request count).
     * Uncheck → check (forceNew) removes previous enable invoices and creates a new one.
     * Quantity is website template requests — not the showcase catalogue (wc_templates).
     *
     * @return list<Invoice>
     */
    public function invoiceExistingDeployedWebsites(Hub $hub, User $actor, bool $forceNew = false): array
    {
        if (! $this->billingEnabled($hub) || ! $hub->moduleEffectivelyEnabled(self::WEBSITE_MODULE_KEY)) {
            return [];
        }

        if ($hub->moduleCoveredByEnabledPackage(self::WEBSITE_MODULE_KEY)) {
            return [];
        }

        if (! $forceNew && $this->existingWtlEnableBilling($hub)) {
            return [];
        }

        $quote = $this->pricing->quote(self::WEBSITE_MODULE_KEY);
        $priced = $this->quoteOneTimeQuantity(
            $hub,
            ModulePricingService::UNIT_PER_WEBSITE,
            (float) $quote['amount'],
            self::WEBSITE_MODULE_KEY
        );

        if ($priced['qty'] < 1) {
            return [];
        }

        $unitAmount = $priced['unit_amount'];
        $count = $priced['qty'];
        $amount = $priced['amount'];

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
            $priced,
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
                'meta' => array_merge([
                    'module_label' => Hub::CHECKLIST_DEFINITIONS[self::WEBSITE_MODULE_KEY]['label'] ?? self::WEBSITE_MODULE_KEY,
                    'billing_cadence' => Invoice::TYPES_ONE_TIME,
                    'billing_unit' => ModulePricingService::UNIT_PER_WEBSITE,
                    'wtl_enable_invoice' => true,
                    'module_enable_invoice' => true,
                    'auto_paid_on_hub_enable' => $isComplimentary,
                ], $priced['meta']),
            ]);

            $invoice = $this->invoices->createForModuleBilling($billing);
            $invoice->forceFill([
                'description' => sprintf(
                    'Module (one time) — Website Template Library — %d website request%s × £%s (%s)',
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
