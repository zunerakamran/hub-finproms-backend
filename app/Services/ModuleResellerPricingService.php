<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleResellerPricing;
use App\Models\ModulePricing;
use InvalidArgumentException;

/**
 * Network→user catalogue when network_resells is on.
 * Only modules whose main (FinProms) catalogue uses per_user / per_user_slots
 * for one-time and/or recurring appear here. Network still pays FinProms at
 * main module_pricing amounts.
 */
class ModuleResellerPricingService
{
    public function __construct(
        private readonly ModulePricingService $catalogue,
        private readonly ModuleInvoiceSettingsService $invoiceSettings
    ) {}

    public function assertResellerMode(Hub $hub): void
    {
        if (! $this->invoiceSettings->forHub($hub)->network_resells) {
            throw new InvalidArgumentException(
                'Reseller module prices are available only while Module invoice settings → Network resells is on.'
            );
        }
    }

    /**
     * Main catalogue rows for this hub that use a per-user unit (one-time or recurring).
     *
     * @return list<array<string, mixed>>
     */
    public function eligibleCatalogueRows(Hub $hub): array
    {
        $this->catalogue->seedDefaultsIfEmpty();

        return array_values(array_filter(
            $this->catalogue->listPricingForHub($hub),
            fn (array $row) => $this->rowIsPerUserEligible($row)
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function rowIsPerUserEligible(array $row): bool
    {
        $oneTime = ModulePricingService::normalizeUnit((string) ($row['billing_unit'] ?? ''));
        $recurring = ModulePricingService::normalizeUnit((string) ($row['recurring_billing_unit'] ?? 'none'));

        $seatUnits = [
            ModulePricingService::UNIT_PER_USER,
            ModulePricingService::UNIT_PER_USER_SLOTS,
        ];

        return in_array($oneTime, $seatUnits, true) || in_array($recurring, $seatUnits, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForHub(Hub $hub): array
    {
        $eligible = $this->eligibleCatalogueRows($hub);
        $keys = array_column($eligible, 'module_key');
        $existing = HubModuleResellerPricing::query()
            ->where('hub_id', $hub->id)
            ->whereIn('module_key', $keys ?: ['__none__'])
            ->get()
            ->keyBy('module_key');

        $out = [];
        foreach ($eligible as $catalogue) {
            $key = (string) $catalogue['module_key'];
            /** @var HubModuleResellerPricing|null $row */
            $row = $existing->get($key);
            $out[] = $this->serialize($hub, $catalogue, $row);
        }

        return $out;
    }

    /**
     * @param  array{amount?: mixed, recurring_amount?: mixed, is_active?: mixed, currency?: mixed}  $data
     */
    public function upsert(Hub $hub, string $moduleKey, array $data): array
    {
        $this->assertResellerMode($hub);

        $eligible = collect($this->eligibleCatalogueRows($hub))
            ->firstWhere('module_key', $moduleKey);
        if (! $eligible) {
            throw new InvalidArgumentException(
                'Only modules whose main prices use per user / per user (slots) can have reseller prices on this hub.'
            );
        }

        $row = HubModuleResellerPricing::query()->firstOrNew([
            'hub_id' => $hub->id,
            'module_key' => $moduleKey,
        ]);

        if (array_key_exists('amount', $data)) {
            $row->amount = round((float) $data['amount'], 2);
        } elseif (! $row->exists) {
            $row->amount = 0;
        }

        if (array_key_exists('recurring_amount', $data)) {
            $row->recurring_amount = round((float) $data['recurring_amount'], 2);
        } elseif (! $row->exists) {
            $row->recurring_amount = 0;
        }

        if (array_key_exists('currency', $data) && filled($data['currency'])) {
            $row->currency = strtolower(trim((string) $data['currency']));
        } elseif (! $row->exists) {
            $row->currency = (string) ($eligible['currency'] ?? 'gbp');
        }

        if (array_key_exists('is_active', $data)) {
            $row->is_active = (bool) $data['is_active'];
        } elseif (! $row->exists) {
            $row->is_active = true;
        }

        $row->save();

        return $this->serialize($hub, $eligible, $row->fresh());
    }

    /**
     * @param  array<string, mixed>  $catalogue
     * @return array<string, mixed>
     */
    public function serialize(Hub $hub, array $catalogue, ?HubModuleResellerPricing $row): array
    {
        return [
            'id' => $row?->id,
            'hub_id' => (int) $hub->id,
            'module_key' => (string) $catalogue['module_key'],
            'label' => $catalogue['label'] ?? $catalogue['module_key'],
            'amount' => $row ? (float) $row->amount : null,
            'recurring_amount' => $row ? (float) $row->recurring_amount : null,
            'currency' => $row?->currency ?? ($catalogue['currency'] ?? 'gbp'),
            'is_active' => $row ? (bool) $row->is_active : true,
            'has_reseller_price' => $row !== null,
            'main_pricing' => [
                'amount' => (float) ($catalogue['amount'] ?? 0),
                'billing_unit' => (string) ($catalogue['billing_unit'] ?? ''),
                'recurring_amount' => (float) ($catalogue['recurring_amount'] ?? 0),
                'recurring_billing_unit' => (string) ($catalogue['recurring_billing_unit'] ?? 'none'),
                'recurring_tier_slot' => $catalogue['recurring_tier_slot'] ?? null,
                'currency' => (string) ($catalogue['currency'] ?? 'gbp'),
            ],
        ];
    }

    public function quoteForUserCharge(Hub $hub, string $moduleKey): ?HubModuleResellerPricing
    {
        return HubModuleResellerPricing::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', $moduleKey)
            ->where('is_active', true)
            ->first();
    }
}
