<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleRecurringBilling;
use App\Models\Invoice;
use App\Models\ModuleBillingBatch;
use App\Models\ModuleBillingBatchUser;
use App\Models\ModulePackage;
use App\Models\ModulePricing;
use App\Models\ModuleRecurringTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ModuleRecurringBillingService
{
    public const UNIT_NONE = 'none';

    public const UNIT_PER_NETWORK = 'per_network';

    /** @deprecated Use ModulePricingService::UNIT_PER_USER_SLOTS */
    public const UNIT_PER_ADVISER = 'per_adviser';

    /** Fixed rate × users (not tier slots). */
    public const UNIT_PER_USER = 'per_user';

    /** Tier-slot seat billing (canonical). */
    public const UNIT_PER_USER_SLOTS = 'per_user_slots';

    public const UNIT_PER_WEBSITE = 'per_website';

    public const UNIT_PER_FIRM = 'per_firm';

    /** Modules billed from per-user slot tables on import / anniversary. */
    public const SEAT_MODULE_KEYS = [
        'module_social_media_template_library',
        'module_social_media_compliance',
        'module_general_compliance',
    ];

    /**
     * Units that use module_recurring_tiers via recurring_tier_slot.
     *
     * @var list<string>
     */
    public const SEAT_UNITS = [
        self::UNIT_PER_USER_SLOTS,
        self::UNIT_PER_ADVISER, // legacy
    ];

    public function __construct(
        private readonly ModulePricingService $pricing,
        private readonly InvoiceService $invoices,
        private readonly ModuleBillingService $oneTimeBilling
    ) {}

    public function billingEnabled(Hub $hub): bool
    {
        return $hub->can('charge_recurring_per_module');
    }

    /**
     * Active billable users for a module: not suspended, not discontinued,
     * and module present on the user's modules allow-list (or unrestricted null).
     *
     * @return list<User>
     */
    public function activeUsersWithModule(Hub $hub, string $moduleKey, ?string $connection = null): array
    {
        $query = User::query()
            ->when($connection, fn ($q) => $q->on($connection))
            ->where('is_suspended', false)
            ->where('is_discontinued', false)
            ->orderBy('id');

        $users = $query->get()->filter(function (User $user) use ($hub, $moduleKey) {
            if (ActingHubService::isControlPlaneRole((string) $user->role)) {
                return false;
            }

            return $user->hasModuleAccess($hub, $moduleKey);
        })->values()->all();

        return $users;
    }

    public function activeUserCountWithModule(Hub $hub, string $moduleKey, ?string $connection = null): int
    {
        return count($this->activeUsersWithModule($hub, $moduleKey, $connection));
    }

    /**
     * Resolve slot tier for a headcount.
     */
    public function resolveTier(int $slot, int $userCount): ?ModuleRecurringTier
    {
        $count = max(0, $userCount);

        return ModuleRecurringTier::query()
            ->where('slot', $slot)
            ->where('is_active', true)
            ->where('min_users', '<=', $count)
            ->where(function ($q) use ($count) {
                $q->whereNull('max_users')->orWhere('max_users', '>=', $count);
            })
            ->orderByDesc('min_users')
            ->first();
    }

    /**
     * Quote Option B: rate from TOTAL active users with module; charge batch only.
     *
     * @param  list<User>  $batchUsers
     * @return array{
     *   module_key: string,
     *   batch_count: int,
     *   total_users: int,
     *   rate_per_user: float,
     *   network_margin_per_user: float,
     *   amount: float,
     *   currency: string,
     *   slot: ?int,
     *   tier: ?array<string, mixed>,
     *   billing_unit: string
     * }|null
     */
    public function quoteSeatBatch(Hub $hub, string $moduleKey, array $batchUsers, ?string $connection = null): ?array
    {
        // Covered by an enabled package with its own recurring — skip individual seat charge.
        if ($this->moduleCoveredByPackageRecurring($hub, $moduleKey)) {
            return null;
        }

        $this->pricing->seedDefaultsIfEmpty();
        $pricing = ModulePricing::query()->where('module_key', $moduleKey)->where('is_active', true)->first();
        $slot = $pricing && $pricing->recurring_tier_slot !== null ? (int) $pricing->recurring_tier_slot : null;
        if (! $pricing || ! $this->isSeatBillingUnit((string) $pricing->recurring_billing_unit, $slot)) {
            return null;
        }

        $slot = (int) ($slot ?: 0);
        if ($slot < 1) {
            return null;
        }

        return $this->buildSeatQuote(
            $hub,
            $moduleKey,
            $batchUsers,
            ModulePricingService::UNIT_PER_USER_SLOTS,
            $slot,
            (string) ($pricing->currency ?: 'gbp'),
            [$moduleKey],
            $connection
        );
    }

    /**
     * Seat quote for an enabled package (per_user_slots / legacy per_adviser + slot).
     *
     * @param  list<User>  $batchUsers
     * @return array<string, mixed>|null
     */
    public function quoteSeatBatchForPackage(
        Hub $hub,
        ModulePackage $package,
        array $batchUsers,
        ?string $connection = null
    ): ?array {
        $unit = (string) ($package->recurring_billing_unit ?: self::UNIT_NONE);
        $slotNullable = $package->recurring_tier_slot !== null ? (int) $package->recurring_tier_slot : null;
        if (! $this->isSeatBillingUnit($unit, $slotNullable)) {
            return null;
        }
        $slot = (int) ($slotNullable ?: 0);
        if ($slot < 1) {
            return null;
        }

        $keys = $package->moduleKeys();
        if ($keys === []) {
            return null;
        }

        return $this->buildSeatQuote(
            $hub,
            $package->billingModuleKey(),
            $batchUsers,
            ModulePricingService::UNIT_PER_USER_SLOTS,
            $slot,
            (string) ($package->currency ?: 'gbp'),
            $keys,
            $connection
        );
    }

    /**
     * @param  ?int  $tierSlot  When unit is legacy `per_user` with a slot, treat as seats
     *                          until data is migrated to `per_user_slots`.
     */
    public function isSeatBillingUnit(string $unit, ?int $tierSlot = null): bool
    {
        if (in_array($unit, self::SEAT_UNITS, true) || ModulePricingService::isSeatUnit($unit)) {
            return true;
        }

        // Pre-migration rows: per_user + slot meant tier seats (now per_user_slots).
        return $unit === self::UNIT_PER_USER && $tierSlot !== null && $tierSlot >= 1;
    }

    /**
     * @param  list<User>  $batchUsers
     * @param  list<string>  $accessModuleKeys  modules used to count active users
     * @return array<string, mixed>|null
     */
    private function buildSeatQuote(
        Hub $hub,
        string $billingKey,
        array $batchUsers,
        string $billingUnit,
        int $slot,
        string $currency,
        array $accessModuleKeys,
        ?string $connection = null
    ): ?array {
        $batchIds = collect($batchUsers)->pluck('id')->all();
        $existing = $this->activeUsersWithAnyModule($hub, $accessModuleKeys, $connection);
        $allIds = collect($existing)->pluck('id')->merge($batchIds)->unique()->values();
        $total = $allIds->count();
        $batchCount = count(array_unique($batchIds));

        if ($batchCount < 1 || $total < 1) {
            return null;
        }

        $tier = $this->resolveTier($slot, $total);
        $rate = $tier ? (float) $tier->rate_per_user : 0.0;
        $margin = $tier ? (float) $tier->network_margin_per_user : 0.0;
        $amount = round($rate * $batchCount, 2);

        return [
            'module_key' => $billingKey,
            'batch_count' => $batchCount,
            'total_users' => $total,
            'rate_per_user' => $rate,
            'network_margin_per_user' => $margin,
            'amount' => $amount,
            'currency' => $currency,
            'slot' => $slot,
            'tier' => $tier ? [
                'id' => $tier->id,
                'min_users' => $tier->min_users,
                'max_users' => $tier->max_users,
                'rate_per_user' => $rate,
                'network_margin_per_user' => $margin,
            ] : null,
            'billing_unit' => $billingUnit,
        ];
    }

    /**
     * @param  list<string>  $moduleKeys
     * @return list<User>
     */
    public function activeUsersWithAnyModule(Hub $hub, array $moduleKeys, ?string $connection = null): array
    {
        if ($moduleKeys === []) {
            return [];
        }

        $query = User::query()
            ->when($connection, fn ($q) => $q->on($connection))
            ->where('is_suspended', false)
            ->where('is_discontinued', false)
            ->orderBy('id');

        return $query->get()->filter(function (User $user) use ($hub, $moduleKeys) {
            if (ActingHubService::isControlPlaneRole((string) $user->role)) {
                return false;
            }
            foreach ($moduleKeys as $moduleKey) {
                if ($user->hasModuleAccess($hub, $moduleKey)) {
                    return true;
                }
            }

            return false;
        })->values()->all();
    }

    public function moduleCoveredByPackageRecurring(Hub $hub, string $moduleKey): bool
    {
        foreach ($this->enabledPackagesWithRecurring($hub) as $package) {
            if (in_array($moduleKey, $package->moduleKeys(), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ModulePackage>
     */
    public function enabledPackagesWithRecurring(Hub $hub): array
    {
        $ids = $hub->enabledModulePackageIds();
        if ($ids === []) {
            return [];
        }

        return ModulePackage::query()
            ->with('items')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where('recurring_billing_unit', '!=', self::UNIT_NONE)
            ->get()
            ->all();
    }

    /**
     * After import commit: create due-today recurring invoices per module for the batch (Option B).
     *
     * @param  list<User>  $importedUsers
     * @return list<Invoice>
     */
    public function invoiceImportBatch(Hub $hub, array $importedUsers, User $actor, ?string $connection = null): array
    {
        if (! $this->billingEnabled($hub) || $importedUsers === []) {
            return [];
        }

        $payer = $this->oneTimeBilling->resolvePayer($actor);
        $created = [];
        $today = Carbon::today();
        $anniversaryDay = min(28, max(1, (int) $today->day));

        foreach (self::SEAT_MODULE_KEYS as $moduleKey) {
            if (! $hub->moduleEffectivelyEnabled($moduleKey)) {
                continue;
            }
            if ($this->moduleCoveredByPackageRecurring($hub, $moduleKey)) {
                continue;
            }

            $batchUsers = array_values(array_filter(
                $importedUsers,
                fn (User $u) => ! $u->isSuspended()
                    && ! $u->isDiscontinued()
                    && $u->hasModuleAccess($hub, $moduleKey)
            ));

            if ($batchUsers === []) {
                continue;
            }

            $quote = $this->quoteSeatBatch($hub, $moduleKey, $batchUsers, $connection);
            if (! $quote || $quote['amount'] <= 0) {
                continue;
            }

            $invoice = DB::transaction(function () use ($hub, $moduleKey, $batchUsers, $quote, $payer, $today, $anniversaryDay) {
                $batch = ModuleBillingBatch::query()->create([
                    'hub_id' => $hub->id,
                    'module_key' => $moduleKey,
                    'anniversary_day' => $anniversaryDay,
                    'started_on' => $today->toDateString(),
                    'next_invoice_on' => $this->nextAnniversaryDate($today, $anniversaryDay)->toDateString(),
                    'status' => ModuleBillingBatch::STATUS_ACTIVE,
                    'initial_user_count' => $quote['batch_count'],
                    'meta' => [
                        'quote' => $quote,
                    ],
                ]);

                foreach ($batchUsers as $user) {
                    ModuleBillingBatchUser::query()->create([
                        'module_billing_batch_id' => $batch->id,
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);
                }

                $billing = HubModuleRecurringBilling::query()->create([
                    'hub_id' => $hub->id,
                    'module_billing_batch_id' => $batch->id,
                    'module_key' => $moduleKey,
                    'billed_user_id' => $payer->id,
                    'user_count' => $quote['batch_count'],
                    'total_users_for_tier' => $quote['total_users'],
                    'rate_per_user' => $quote['rate_per_user'],
                    'amount' => $quote['amount'],
                    'currency' => $quote['currency'],
                    'status' => HubModuleRecurringBilling::STATUS_UNPAID,
                    'payment_status' => HubModuleRecurringBilling::STATUS_UNPAID,
                    'due_on' => $today->toDateString(),
                    'period_starts_on' => $today->toDateString(),
                    'period_ends_on' => $batch->next_invoice_on?->toDateString(),
                    'billing_kind' => HubModuleRecurringBilling::KIND_BATCH,
                    'meta' => [
                        'quote' => $quote,
                        'module_label' => Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey,
                        'recurring_billing_unit' => $quote['billing_unit'] ?? null,
                    ],
                ]);

                return $this->invoices->createForModuleRecurringBilling($billing);
            });

            if ($invoice) {
                $created[] = $invoice;
            }
        }

        // Seat packages: one batch per enabled package with per_user_slots (or legacy seat units).
        foreach ($this->enabledPackagesWithRecurring($hub) as $package) {
            $unit = (string) ($package->recurring_billing_unit ?: self::UNIT_NONE);
            $pkgSlot = $package->recurring_tier_slot !== null ? (int) $package->recurring_tier_slot : null;
            if (! $this->isSeatBillingUnit($unit, $pkgSlot)) {
                continue;
            }
            $packageKeys = $package->moduleKeys();
            $batchUsers = array_values(array_filter(
                $importedUsers,
                function (User $u) use ($hub, $packageKeys) {
                    if ($u->isSuspended() || $u->isDiscontinued()) {
                        return false;
                    }
                    foreach ($packageKeys as $key) {
                        if ($u->hasModuleAccess($hub, $key)) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
            if ($batchUsers === []) {
                continue;
            }

            $quote = $this->quoteSeatBatchForPackage($hub, $package, $batchUsers, $connection);
            if (! $quote || $quote['amount'] <= 0) {
                continue;
            }

            $billingKey = $package->billingModuleKey();
            $invoice = DB::transaction(function () use (
                $hub,
                $package,
                $billingKey,
                $batchUsers,
                $quote,
                $payer,
                $today,
                $anniversaryDay
            ) {
                $batch = ModuleBillingBatch::query()->create([
                    'hub_id' => $hub->id,
                    'module_key' => $billingKey,
                    'anniversary_day' => $anniversaryDay,
                    'started_on' => $today->toDateString(),
                    'next_invoice_on' => $this->nextAnniversaryDate($today, $anniversaryDay)->toDateString(),
                    'status' => ModuleBillingBatch::STATUS_ACTIVE,
                    'initial_user_count' => $quote['batch_count'],
                    'meta' => [
                        'quote' => $quote,
                        'module_package_id' => $package->id,
                        'module_package_module_keys' => $package->moduleKeys(),
                    ],
                ]);

                foreach ($batchUsers as $user) {
                    ModuleBillingBatchUser::query()->create([
                        'module_billing_batch_id' => $batch->id,
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);
                }

                $billing = HubModuleRecurringBilling::query()->create([
                    'hub_id' => $hub->id,
                    'module_billing_batch_id' => $batch->id,
                    'module_key' => $billingKey,
                    'billed_user_id' => $payer->id,
                    'user_count' => $quote['batch_count'],
                    'total_users_for_tier' => $quote['total_users'],
                    'rate_per_user' => $quote['rate_per_user'],
                    'amount' => $quote['amount'],
                    'currency' => $quote['currency'],
                    'status' => HubModuleRecurringBilling::STATUS_UNPAID,
                    'payment_status' => HubModuleRecurringBilling::STATUS_UNPAID,
                    'due_on' => $today->toDateString(),
                    'period_starts_on' => $today->toDateString(),
                    'period_ends_on' => $batch->next_invoice_on?->toDateString(),
                    'billing_kind' => HubModuleRecurringBilling::KIND_BATCH,
                    'meta' => [
                        'quote' => $quote,
                        'module_label' => $package->name,
                        'module_package_id' => $package->id,
                        'module_package_enable_invoice' => false,
                        'recurring_billing_unit' => $quote['billing_unit'] ?? null,
                    ],
                ]);

                return $this->invoices->createForModuleRecurringBilling($billing);
            });

            if ($invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * Create anniversary invoices for batches due today (active users only).
     *
     * @return list<Invoice>
     */
    public function processAnniversaryInvoices(?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $created = [];

        $batches = ModuleBillingBatch::query()
            ->where('status', ModuleBillingBatch::STATUS_ACTIVE)
            ->whereDate('next_invoice_on', '<=', $on->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            $hub = $batch->hub;
            if (! $hub || ! $this->billingEnabled($hub)) {
                continue;
            }

            $invoice = $this->invoiceAnniversaryBatch($batch, $on);
            if ($invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    public function invoiceAnniversaryBatch(ModuleBillingBatch $batch, ?Carbon $on = null): ?Invoice
    {
        $on ??= Carbon::today();
        $hub = $batch->hub;
        if (! $hub || ! $this->billingEnabled($hub)) {
            return null;
        }

        $moduleKey = $batch->module_key;
        $packageId = ModulePackage::parseBillingPackageId((string) $moduleKey);
        $package = null;
        $accessKeys = [$moduleKey];

        if ($packageId) {
            if (! in_array($packageId, $hub->enabledModulePackageIds(), true)) {
                return null;
            }
            $package = ModulePackage::query()->with('items')->find($packageId);
            if (! $package || ! $package->is_active) {
                return null;
            }
            $accessKeys = $package->moduleKeys();
        } elseif (! $hub->moduleEffectivelyEnabled($moduleKey)) {
            return null;
        }

        $batch->loadMissing('users');
        $userIds = $batch->users->pluck('user_id')->all();
        if ($userIds === []) {
            return null;
        }

        // Active cohort + tier total must be read from the hub DB (remote for WL).
        $activeInBatch = $this->oneTimeBilling->onHubDatabase($hub, function () use ($userIds, $hub, $accessKeys) {
            return User::query()
                ->whereIn('id', $userIds)
                ->where('is_suspended', false)
                ->where('is_discontinued', false)
                ->get()
                ->filter(function (User $u) use ($hub, $accessKeys) {
                    foreach ($accessKeys as $key) {
                        if ($u->hasModuleAccess($hub, $key)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values()
                ->all();
        });

        if ($activeInBatch === []) {
            $batch->update(['status' => ModuleBillingBatch::STATUS_CANCELED]);

            return null;
        }

        $quote = $this->oneTimeBilling->onHubDatabase($hub, function (?string $connection) use ($hub, $moduleKey, $package, $activeInBatch) {
            if ($package) {
                return $this->quoteSeatBatchForPackage($hub, $package, $activeInBatch, $connection);
            }

            return $this->quoteSeatBatch($hub, $moduleKey, $activeInBatch, $connection);
        });
        if (! $quote || $quote['amount'] <= 0) {
            return null;
        }

        // Anniversary charges remaining active users in this cohort at rate(total).
        $batchCount = count($activeInBatch);
        $amount = round($quote['rate_per_user'] * $batchCount, 2);
        $payerId = HubModuleRecurringBilling::query()
            ->where('module_billing_batch_id', $batch->id)
            ->orderBy('id')
            ->value('billed_user_id');
        $payer = $payerId ? User::query()->find($payerId) : User::query()->orderBy('id')->first();
        if (! $payer) {
            return null;
        }

        $moduleLabel = $package
            ? $package->name
            : (Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey);

        return DB::transaction(function () use (
            $hub,
            $batch,
            $moduleKey,
            $quote,
            $batchCount,
            $amount,
            $payer,
            $on,
            $moduleLabel,
            $package
        ) {
            $nextOn = $this->nextAnniversaryDate($on, (int) $batch->anniversary_day);

            $billing = HubModuleRecurringBilling::query()->create([
                'hub_id' => $hub->id,
                'module_billing_batch_id' => $batch->id,
                'module_key' => $moduleKey,
                'billed_user_id' => $payer->id,
                'user_count' => $batchCount,
                'total_users_for_tier' => $quote['total_users'],
                'rate_per_user' => $quote['rate_per_user'],
                'amount' => $amount,
                'currency' => $quote['currency'],
                'status' => HubModuleRecurringBilling::STATUS_UNPAID,
                'payment_status' => HubModuleRecurringBilling::STATUS_UNPAID,
                'due_on' => $on->toDateString(),
                'period_starts_on' => $on->toDateString(),
                'period_ends_on' => $nextOn->toDateString(),
                'billing_kind' => HubModuleRecurringBilling::KIND_ANNIVERSARY,
                'meta' => [
                    'quote' => $quote,
                    'module_label' => $moduleLabel,
                    'module_package_id' => $package?->id,
                    'recurring_billing_unit' => $quote['billing_unit'] ?? null,
                ],
            ]);

            $batch->update(['next_invoice_on' => $nextOn->toDateString()]);

            return $this->invoices->createForModuleRecurringBilling($billing);
        });
    }

    /**
     * Flat recurring modules (per_network / per_firm / per_user fixed / per_website)
     * — one invoice per hub+module+calendar month on renew day (idempotent).
     * Seat units (per_user_slots) use import / anniversary batches instead.
     *
     * @return list<Invoice>
     */
    public function invoiceFlatRecurringForMonth(Hub $hub, User $actor, ?Carbon $on = null): array
    {
        if (! $this->billingEnabled($hub)) {
            return [];
        }

        $on ??= Carbon::today();
        $periodStart = $on->copy()->startOfMonth()->toDateString();
        $periodEnd = $on->copy()->endOfMonth()->toDateString();
        $payer = $this->oneTimeBilling->resolvePayer($actor);
        $this->pricing->seedDefaultsIfEmpty();
        $created = [];

        $flatUnits = [
            self::UNIT_PER_NETWORK,
            self::UNIT_PER_WEBSITE,
            self::UNIT_PER_FIRM,
            self::UNIT_PER_USER, // fixed rate × users (not slots)
        ];

        $rows = ModulePricing::query()
            ->where('is_active', true)
            ->whereIn('recurring_billing_unit', $flatUnits)
            ->get();

        foreach ($rows as $pricing) {
            $moduleKey = (string) $pricing->module_key;
            $unit = (string) $pricing->recurring_billing_unit;
            $slot = $pricing->recurring_tier_slot !== null ? (int) $pricing->recurring_tier_slot : null;
            // Skip legacy per_user+slot rows — those still belong on the seat path.
            if ($this->isSeatBillingUnit($unit, $slot)) {
                continue;
            }
            if (! $hub->moduleEffectivelyEnabled($moduleKey)) {
                continue;
            }
            if ($this->moduleCoveredByPackageRecurring($hub, $moduleKey)) {
                continue;
            }

            $invoice = $this->createFlatRecurringInvoice(
                $hub,
                $payer,
                $moduleKey,
                $unit,
                (float) ($pricing->recurring_amount ?? 0),
                (string) ($pricing->currency ?: 'gbp'),
                Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey,
                $on,
                $periodStart,
                $periodEnd
            );
            if ($invoice) {
                $created[] = $invoice;
            }
        }

        foreach ($this->enabledPackagesWithRecurring($hub) as $package) {
            $unit = (string) ($package->recurring_billing_unit ?: self::UNIT_NONE);
            $pkgSlot = $package->recurring_tier_slot !== null ? (int) $package->recurring_tier_slot : null;
            if (! in_array($unit, $flatUnits, true) || $this->isSeatBillingUnit($unit, $pkgSlot)) {
                continue;
            }

            $invoice = $this->createFlatRecurringInvoice(
                $hub,
                $payer,
                $package->billingModuleKey(),
                $unit,
                (float) ($package->recurring_amount ?? 0),
                (string) ($package->currency ?: 'gbp'),
                $package->name,
                $on,
                $periodStart,
                $periodEnd,
                ['module_package_id' => $package->id]
            );
            if ($invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     */
    private function createFlatRecurringInvoice(
        Hub $hub,
        User $payer,
        string $moduleKey,
        string $unit,
        float $rate,
        string $currency,
        string $label,
        Carbon $on,
        string $periodStart,
        string $periodEnd,
        array $extraMeta = []
    ): ?Invoice {
        $exists = HubModuleRecurringBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', $moduleKey)
            ->where('billing_kind', HubModuleRecurringBilling::KIND_FLAT)
            ->whereDate('period_starts_on', $periodStart)
            ->where('status', '!=', HubModuleRecurringBilling::STATUS_CANCELED)
            ->exists();
        if ($exists) {
            return null;
        }

        $qty = 1;
        if ($unit === self::UNIT_PER_FIRM) {
            $qty = max(0, $this->oneTimeBilling->countFirms($hub));
        } elseif ($unit === self::UNIT_PER_WEBSITE) {
            $qty = max(0, $this->oneTimeBilling->countDeployedWebsites($hub));
        } elseif ($unit === self::UNIT_PER_USER) {
            // Fixed per-user: count active users with access to this module / any package module.
            $packageId = ModulePackage::parseBillingPackageId($moduleKey);
            if ($packageId) {
                $package = ModulePackage::query()->with('items')->find($packageId);
                $keys = $package ? $package->moduleKeys() : [];
                $qty = max(0, count($this->activeUsersWithAnyModule($hub, $keys)));
            } else {
                $qty = max(0, $this->activeUserCountWithModule($hub, $moduleKey));
            }
        }

        $amount = round($rate * $qty, 2);
        if ($qty < 1 || $amount <= 0) {
            return null;
        }

        return DB::transaction(function () use (
            $hub,
            $moduleKey,
            $payer,
            $qty,
            $rate,
            $amount,
            $currency,
            $unit,
            $label,
            $on,
            $periodStart,
            $periodEnd,
            $extraMeta
        ) {
            $billing = HubModuleRecurringBilling::query()->create([
                'hub_id' => $hub->id,
                'module_billing_batch_id' => null,
                'module_key' => $moduleKey,
                'billed_user_id' => $payer->id,
                'user_count' => $qty,
                'total_users_for_tier' => $qty,
                'rate_per_user' => $rate,
                'amount' => $amount,
                'currency' => $currency,
                'status' => HubModuleRecurringBilling::STATUS_UNPAID,
                'payment_status' => HubModuleRecurringBilling::STATUS_UNPAID,
                'due_on' => $on->toDateString(),
                'period_starts_on' => $periodStart,
                'period_ends_on' => $periodEnd,
                'billing_kind' => HubModuleRecurringBilling::KIND_FLAT,
                'meta' => array_merge([
                    'recurring_billing_unit' => $unit,
                    'module_label' => $label,
                ], $extraMeta),
            ]);

            return $this->invoices->createForModuleRecurringBilling($billing);
        });
    }

    /**
     * Run flat recurring for every hub whose renew day is today.
     *
     * @return list<Invoice>
     */
    public function processFlatRecurringOnRenewDay(?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $created = [];
        $hubs = Hub::query()->where('is_active', true)->get();

        foreach ($hubs as $hub) {
            if ((int) $on->day !== $hub->advisorBillingRenewDay()) {
                continue;
            }
            if (! $this->billingEnabled($hub)) {
                continue;
            }

            $payer = User::query()
                ->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin', User::ROLE_POWER_ADMIN])
                ->orderBy('id')
                ->first();
            if (! $payer) {
                continue;
            }

            foreach ($this->invoiceFlatRecurringForMonth($hub, $payer, $on) as $invoice) {
                $created[] = $invoice;
            }
        }

        return $created;
    }

    private function nextAnniversaryDate(Carbon $from, int $anniversaryDay): Carbon
    {
        $next = $from->copy()->addMonthNoOverflow();
        $day = min(max(1, $anniversaryDay), $next->daysInMonth);

        return $next->day($day)->startOfDay();
    }

    /**
     * After grace day: suspend users on unpaid recurring seat invoices; disable modules on unpaid one-time.
     *
     * @return array{suspended_users: int, disabled_modules: int}
     */
    public function enforceGracePenalties(?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $suspended = 0;
        $disabled = 0;

        $hubs = Hub::query()->where('is_active', true)->get();
        foreach ($hubs as $hub) {
            $graceDay = $hub->billingGraceDay();
            $renewDay = $hub->advisorBillingRenewDay();

            // Seat suspensions: only on/after the hub's grace day of the month.
            if ((int) $on->day >= $graceDay) {
                $recurring = HubModuleRecurringBilling::query()
                    ->where('hub_id', $hub->id)
                    ->where('status', HubModuleRecurringBilling::STATUS_UNPAID)
                    ->whereDate('due_on', '<=', $on->toDateString())
                    ->whereIn('module_key', self::SEAT_MODULE_KEYS)
                    ->get();

                foreach ($recurring as $billing) {
                    $userIds = ModuleBillingBatchUser::query()
                        ->where('module_billing_batch_id', $billing->module_billing_batch_id)
                        ->pluck('user_id');
                    $count = (int) $this->oneTimeBilling->onHubDatabase($hub, function () use ($userIds) {
                        return User::query()
                            ->whereIn('id', $userIds)
                            ->where('is_suspended', false)
                            ->where('is_discontinued', false)
                            ->update(['is_suspended' => true]);
                    });
                    $suspended += $count;
                }
            }

            // Unpaid one-time module invoices past their due+grace window → turn module off.
            // Grace is relative to each invoice's due date (renew-day due), NOT "calendar day
            // of month >= grace day", so enabling a module mid-month does not immediately disable it.
            $oneTime = \App\Models\HubModuleBilling::query()
                ->where('hub_id', $hub->id)
                ->where('status', \App\Models\HubModuleBilling::STATUS_UNPAID)
                ->whereNotIn('module_key', Hub::LOCKED_MODULE_KEYS)
                ->get();

            if ($oneTime->isEmpty()) {
                continue;
            }

            $checklist = $hub->resolvedChecklist();
            $changed = false;
            foreach ($oneTime as $billing) {
                $invoice = $billing->invoice;
                if (! $invoice) {
                    continue;
                }

                // Older enables stored due_on = enable day. Move those onto the next renew day
                // so grace cannot uncheck modules before the renew cycle they belong to.
                if ($invoice->due_on) {
                    $rawDue = Carbon::parse($invoice->due_on)->startOfDay();
                    if ((int) $rawDue->day !== $renewDay) {
                        $corrected = $hub->nextModuleInvoiceDueDate($on);
                        $invoice->forceFill(['due_on' => $corrected->toDateString()])->save();
                    }
                }

                $due = $invoice->due_on
                    ? Carbon::parse($invoice->due_on)->startOfDay()
                    : ($invoice->issued_at ? Carbon::parse($invoice->issued_at)->startOfDay() : null);
                if (! $due || $due->gt($on)) {
                    continue;
                }
                $graceDate = $this->graceDateForInvoiceDue($due, $renewDay, $graceDay);
                if ($on->lt($graceDate)) {
                    continue;
                }
                if (! empty($checklist[$billing->module_key])) {
                    $checklist[$billing->module_key] = false;
                    $changed = true;
                    $disabled++;
                }
            }
            if ($changed) {
                $hub->checklist = $checklist;
                $hub->save();
            }
        }

        return ['suspended_users' => $suspended, 'disabled_modules' => $disabled];
    }

    /**
     * Grace cutoff for a one-time invoice due date.
     * Normal case (due on renew day): grace day in that same month.
     * Mis-dated mid-month due: allow the renew→grace gap after the due date.
     */
    private function graceDateForInvoiceDue(Carbon $due, int $renewDay, int $graceDay): Carbon
    {
        $graceInDueMonth = $due->copy()->day(min($graceDay, $due->daysInMonth))->startOfDay();
        if ($due->lte($graceInDueMonth)) {
            return $graceInDueMonth;
        }

        return $due->copy()->addDays(max(0, $graceDay - $renewDay))->startOfDay();
    }
}
