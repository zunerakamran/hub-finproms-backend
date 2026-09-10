<?php

namespace App\Services;

use App\Models\AdvisorPricingTier;
use App\Models\User;
use InvalidArgumentException;

class AdvisorPricingService
{
    /**
     * Resolve rate for a given advisor headcount.
     * Picks the active tier with the highest min_advisors that is <= count.
     *
     * @return array{
     *   advisor_count: int,
     *   rate_per_advisor: float,
     *   amount: float,
     *   currency: string,
     *   tier: ?array{id: int, label: ?string, min_advisors: int, rate_per_advisor: float}
     * }
     */
    public function quote(int $advisorCount): array
    {
        $count = max(0, $advisorCount);
        $tier = $this->resolveTier($count);
        $rate = $tier ? (float) $tier->rate_per_advisor : 0.0;
        $amount = round($rate * $count, 2);

        return [
            'advisor_count' => $count,
            'rate_per_advisor' => $rate,
            'amount' => $amount,
            'currency' => 'gbp',
            'tier' => $tier ? [
                'id' => $tier->id,
                'label' => $tier->label,
                'min_advisors' => $tier->min_advisors,
                'rate_per_advisor' => (float) $tier->rate_per_advisor,
            ] : null,
        ];
    }

    public function resolveTier(int $advisorCount): ?AdvisorPricingTier
    {
        if ($advisorCount < 1) {
            return null;
        }

        return AdvisorPricingTier::query()
            ->where('is_active', true)
            ->where('min_advisors', '<=', $advisorCount)
            ->orderByDesc('min_advisors')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * @return list<AdvisorPricingTier>
     */
    public function listTiers(bool $activeOnly = false): array
    {
        $query = AdvisorPricingTier::query()->orderBy('min_advisors')->orderBy('sort_order');
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get()->all();
    }

    /**
     * @param  array{label?: ?string, min_advisors: int, rate_per_advisor: float|int|string, is_active?: bool, sort_order?: int}  $data
     */
    public function createTier(array $data): AdvisorPricingTier
    {
        return AdvisorPricingTier::query()->create([
            'label' => $data['label'] ?? null,
            'min_advisors' => (int) $data['min_advisors'],
            'rate_per_advisor' => (float) $data['rate_per_advisor'],
            'is_active' => array_key_exists('is_active', $data)
                ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN)
                : true,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    /**
     * @param  array{label?: ?string, min_advisors?: int, rate_per_advisor?: float|int|string, is_active?: bool, sort_order?: int}  $data
     */
    public function updateTier(AdvisorPricingTier $tier, array $data): AdvisorPricingTier
    {
        if (array_key_exists('label', $data)) {
            $tier->label = $data['label'];
        }
        if (array_key_exists('min_advisors', $data)) {
            $tier->min_advisors = (int) $data['min_advisors'];
        }
        if (array_key_exists('rate_per_advisor', $data)) {
            $tier->rate_per_advisor = (float) $data['rate_per_advisor'];
        }
        if (array_key_exists('is_active', $data)) {
            $tier->is_active = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('sort_order', $data)) {
            $tier->sort_order = (int) $data['sort_order'];
        }
        $tier->save();

        return $tier->fresh();
    }

    public function deleteTier(AdvisorPricingTier $tier): void
    {
        $tier->delete();
    }

    public function seedDefaultsIfEmpty(): void
    {
        if (AdvisorPricingTier::query()->exists()) {
            return;
        }

        $defaults = [
            ['label' => 'Starter (1+)', 'min_advisors' => 1, 'rate_per_advisor' => 100.00, 'sort_order' => 1],
            ['label' => 'Growth (100+)', 'min_advisors' => 100, 'rate_per_advisor' => 70.00, 'sort_order' => 2],
            ['label' => 'Scale (150+)', 'min_advisors' => 150, 'rate_per_advisor' => 50.00, 'sort_order' => 3],
        ];

        foreach ($defaults as $row) {
            $this->createTier($row);
        }
    }

    public function currentAdvisorCount(): int
    {
        return User::query()
            ->where('is_advisor', true)
            ->where('is_suspended', false)
            ->count();
    }

    public function assertHasTiers(): void
    {
        if (! AdvisorPricingTier::query()->where('is_active', true)->exists()) {
            throw new InvalidArgumentException(
                'No active advisor pricing tiers configured. Enable "Set advisor billing rates / quotas" for a role and configure tiers first.'
            );
        }
    }
}
