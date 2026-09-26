<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CreditsReportService
{
    /**
     * Build a member-facing credits report.
     * Shared hubs: earned from paid plans + spent on unlocks.
     * White-labelled hubs: no plans — allotment from hub subscriber credits + spends.
     *
     * @return array<string, mixed>
     */
    public function forUser(User $subject, Hub $hub, bool $hubUnlimited, bool $isActing): array
    {
        $isWhiteLabel = $hub->isWhiteLabel();
        $ledger = $this->buildLedger($subject, $isWhiteLabel);
        $daily = $this->buildDaily($ledger);

        $totalEarned = (int) $ledger->where('direction', 'in')->sum('credits');
        $totalSpent = (int) $ledger->where('direction', 'out')->sum('credits');

        $allotment = null;
        if ($isWhiteLabel) {
            $config = $hub->subscriberCreditsConfig();
            $allotment = [
                'unlimited' => (bool) $config['unlimited'],
                'credits' => $config['credits'],
                'label' => $config['unlimited']
                    ? 'Unlimited subscriber credits'
                    : ((int) $config['credits']).' credits per subscriber period',
            ];
        }

        return [
            'balance' => (int) $subject->credits,
            'has_unlimited_credits' => $subject->hasUnlimitedCredits($hubUnlimited),
            'plans_enabled' => ! $isWhiteLabel,
            'is_white_label' => $isWhiteLabel,
            'allotment' => $allotment,
            'total_earned' => $totalEarned,
            'total_spent' => $totalSpent,
            'transaction_count' => $ledger->count(),
            'days_with_activity' => $daily->count(),
            'is_acting' => $isActing,
            'subject_name' => $subject->name,
            'ledger' => $ledger->values()->all(),
            'daily' => $daily->values()->all(),
        ];
    }

    private function buildLedger(User $subject, bool $isWhiteLabel): Collection
    {
        $entries = collect();

        // White-labelled hubs have no subscription plans.
        if (! $isWhiteLabel) {
            $subscriptions = $subject->subscriptions()
                ->with('plan:id,name')
                ->where('payment_status', 'paid')
                ->where('credits_granted', '>', 0)
                ->get(['id', 'subscription_plan_id', 'credits_granted', 'starts_at', 'created_at', 'status']);

            foreach ($subscriptions as $subscription) {
                $at = $subscription->starts_at ?? $subscription->created_at;
                $planName = $subscription->plan?->name ?: 'Subscription';
                $entries->push([
                    'id' => 'sub-'.$subscription->id,
                    'direction' => 'in',
                    'type' => 'subscription',
                    'type_label' => 'Plan credits',
                    'description' => $planName.' · '.$subscription->credits_granted.' credits granted',
                    'item_title' => $planName,
                    'credits' => (int) $subscription->credits_granted,
                    'occurred_at' => optional($at)?->toIso8601String(),
                    'date' => optional($at)?->toDateString(),
                    'reference_id' => (int) $subscription->id,
                ]);
            }
        }

        $postPurchases = $subject->purchases()
            ->with('post:id,title,type')
            ->where('credits_spent', '>', 0)
            ->get(['id', 'post_id', 'credits_spent', 'purchased_at']);

        foreach ($postPurchases as $purchase) {
            $at = $purchase->purchased_at;
            $title = $purchase->post?->title ?: ('Post #'.$purchase->post_id);
            $kind = $purchase->post?->type ?: 'post';
            $entries->push([
                'id' => 'post-'.$purchase->id,
                'direction' => 'out',
                'type' => 'post_purchase',
                'type_label' => 'Post unlock',
                'description' => 'Unlocked '.$title.($kind ? ' ('.$kind.')' : ''),
                'item_title' => $title,
                'credits' => (int) $purchase->credits_spent,
                'occurred_at' => optional($at)?->toIso8601String(),
                'date' => optional($at)?->toDateString(),
                'reference_id' => (int) $purchase->id,
            ]);
        }

        $bundlePurchases = $subject->bundlePurchases()
            ->with('bundle:id,title')
            ->where('credits_spent', '>', 0)
            ->get(['id', 'bundle_id', 'credits_spent', 'purchased_at']);

        foreach ($bundlePurchases as $purchase) {
            $at = $purchase->purchased_at;
            $title = $purchase->bundle?->title ?: ('Bundle #'.$purchase->bundle_id);
            $entries->push([
                'id' => 'bundle-'.$purchase->id,
                'direction' => 'out',
                'type' => 'bundle_purchase',
                'type_label' => 'Bundle unlock',
                'description' => 'Unlocked bundle '.$title,
                'item_title' => $title,
                'credits' => (int) $purchase->credits_spent,
                'occurred_at' => optional($at)?->toIso8601String(),
                'date' => optional($at)?->toDateString(),
                'reference_id' => (int) $purchase->id,
            ]);
        }

        return $entries
            ->sortByDesc(fn (array $row) => $row['occurred_at'] ?? '')
            ->values();
    }

    private function buildDaily(Collection $ledger): Collection
    {
        return $ledger
            ->filter(fn (array $row) => ! empty($row['date']))
            ->groupBy('date')
            ->map(function (Collection $rows, string $date) {
                $earned = (int) $rows->where('direction', 'in')->sum('credits');
                $spent = (int) $rows->where('direction', 'out')->sum('credits');

                return [
                    'id' => $date,
                    'date' => $date,
                    'date_label' => Carbon::parse($date)->format('d M Y'),
                    'earned' => $earned,
                    'spent' => $spent,
                    'net' => $earned - $spent,
                    'transactions' => $rows->count(),
                ];
            })
            ->sortByDesc('date')
            ->values();
    }
}
