<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleRecurringBilling;
use App\Models\Invoice;
use App\Models\ModuleBillingBatch;
use App\Models\ModuleBillingBatchUser;
use App\Models\ModulePricing;
use App\Models\ModuleRecurringTier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ModuleRecurringBillingService
{
    public const UNIT_NONE = 'none';

    public const UNIT_PER_NETWORK = 'per_network';

    public const UNIT_PER_ADVISER = 'per_adviser';

    public const UNIT_PER_USER = 'per_user';

    public const UNIT_PER_WEBSITE = 'per_website';

    public const UNIT_PER_FIRM = 'per_firm';

    /** Modules billed from per-user slot tables on import / anniversary. */
    public const SEAT_MODULE_KEYS = [
        'module_social_media_template_library',
        'module_social_media_compliance',
        'module_general_compliance',
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
        $this->pricing->seedDefaultsIfEmpty();
        $pricing = ModulePricing::query()->where('module_key', $moduleKey)->where('is_active', true)->first();
        if (! $pricing || ! in_array($pricing->recurring_billing_unit, [self::UNIT_PER_USER, self::UNIT_PER_ADVISER], true)) {
            return null;
        }

        $slot = (int) ($pricing->recurring_tier_slot ?: 0);
        if ($slot < 1) {
            return null;
        }

        $batchIds = collect($batchUsers)->pluck('id')->all();
        $existing = $this->activeUsersWithModule($hub, $moduleKey, $connection);
        // Total after batch: unique active users including this batch.
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
            'module_key' => $moduleKey,
            'batch_count' => $batchCount,
            'total_users' => $total,
            'rate_per_user' => $rate,
            'network_margin_per_user' => $margin,
            'amount' => $amount,
            'currency' => $pricing->currency ?: 'gbp',
            'slot' => $slot,
            'tier' => $tier ? [
                'id' => $tier->id,
                'min_users' => $tier->min_users,
                'max_users' => $tier->max_users,
                'rate_per_user' => $rate,
                'network_margin_per_user' => $margin,
            ] : null,
            'billing_unit' => (string) $pricing->recurring_billing_unit,
        ];
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
        if (! $hub->moduleEffectivelyEnabled($moduleKey)) {
            return null;
        }

        $batch->loadMissing('users');
        $userIds = $batch->users->pluck('user_id')->all();
        if ($userIds === []) {
            return null;
        }

        // Active cohort + tier total must be read from the hub DB (remote for WL).
        $activeInBatch = $this->oneTimeBilling->onHubDatabase($hub, function () use ($userIds, $hub, $moduleKey) {
            return User::query()
                ->whereIn('id', $userIds)
                ->where('is_suspended', false)
                ->where('is_discontinued', false)
                ->get()
                ->filter(fn (User $u) => $u->hasModuleAccess($hub, $moduleKey))
                ->values()
                ->all();
        });

        if ($activeInBatch === []) {
            $batch->update(['status' => ModuleBillingBatch::STATUS_CANCELED]);

            return null;
        }

        $quote = $this->oneTimeBilling->onHubDatabase($hub, function (?string $connection) use ($hub, $moduleKey, $activeInBatch) {
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

        return DB::transaction(function () use ($hub, $batch, $moduleKey, $quote, $batchCount, $amount, $payer, $on) {
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
                    'module_label' => Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey,
                    'recurring_billing_unit' => $quote['billing_unit'] ?? null,
                ],
            ]);

            $batch->update(['next_invoice_on' => $nextOn->toDateString()]);

            return $this->invoices->createForModuleRecurringBilling($billing);
        });
    }

    /**
     * Flat recurring modules (per_network / per_website / per_firm) — one invoice
     * per hub+module+calendar month on renew day (idempotent).
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
        ];

        $rows = ModulePricing::query()
            ->where('is_active', true)
            ->whereIn('recurring_billing_unit', $flatUnits)
            ->get();

        foreach ($rows as $pricing) {
            $moduleKey = (string) $pricing->module_key;
            if (! $hub->moduleEffectivelyEnabled($moduleKey)) {
                continue;
            }

            $exists = HubModuleRecurringBilling::query()
                ->where('hub_id', $hub->id)
                ->where('module_key', $moduleKey)
                ->where('billing_kind', HubModuleRecurringBilling::KIND_FLAT)
                ->whereDate('period_starts_on', $periodStart)
                ->where('status', '!=', HubModuleRecurringBilling::STATUS_CANCELED)
                ->exists();
            if ($exists) {
                continue;
            }

            $qty = 1;
            $unit = (string) $pricing->recurring_billing_unit;
            if ($unit === self::UNIT_PER_FIRM) {
                $qty = max(0, $this->oneTimeBilling->countFirms($hub));
            } elseif ($unit === self::UNIT_PER_WEBSITE) {
                // Count deployed WC template requests (showcase / advisor websites).
                $qty = max(0, $this->oneTimeBilling->countDeployedWebsites($hub));
            }

            $rate = (float) ($pricing->recurring_amount ?? 0);
            $amount = round($rate * $qty, 2);
            if ($qty < 1 || $amount <= 0) {
                continue;
            }

            $invoice = DB::transaction(function () use (
                $hub,
                $moduleKey,
                $payer,
                $qty,
                $rate,
                $amount,
                $pricing,
                $unit,
                $on,
                $periodStart,
                $periodEnd
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
                    'currency' => $pricing->currency ?: 'gbp',
                    'status' => HubModuleRecurringBilling::STATUS_UNPAID,
                    'payment_status' => HubModuleRecurringBilling::STATUS_UNPAID,
                    'due_on' => $on->toDateString(),
                    'period_starts_on' => $periodStart,
                    'period_ends_on' => $periodEnd,
                    'billing_kind' => HubModuleRecurringBilling::KIND_FLAT,
                    'meta' => [
                        'recurring_billing_unit' => $unit,
                        'module_label' => Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey,
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
            if ((int) $on->day < $graceDay) {
                continue;
            }

            // Unpaid recurring seat invoices due on/before today → suspend cohort users still unpaid.
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

            // Unpaid one-time module invoices past grace → turn module off on checklist.
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
                // Only enforce when invoice is due (issued before/on grace window).
                $invoice = $billing->invoice;
                $due = $invoice?->due_on ? Carbon::parse($invoice->due_on) : ($invoice?->issued_at ? Carbon::parse($invoice->issued_at) : null);
                if ($due && $due->gt($on)) {
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
}
