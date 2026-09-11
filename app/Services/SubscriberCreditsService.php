<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;

class SubscriberCreditsService
{
    /**
     * Apply the hub's current subscriber credit allotment to one advisor.
     * Unlimited → flag on, balance left alone (or 0 on fresh create).
     * Limited → flag off, balance SET to the hub allotment (not incremented).
     */
    public function applyToAdvisor(User $advisor, Hub $hub, bool $resetBalance = true): void
    {
        if (! $advisor->isAdvisor()) {
            return;
        }

        if ($hub->givesUnlimitedSubscriberCredits()) {
            $advisor->has_unlimited_credits = true;
            if ($resetBalance && $advisor->credits === null) {
                $advisor->credits = 0;
            }
            $advisor->save();
            $this->syncSubscriptionCreditsGranted($advisor, 0);

            return;
        }

        $allotment = $hub->subscriberCreditsPerPeriod();
        $advisor->has_unlimited_credits = false;
        if ($resetBalance) {
            $advisor->credits = $allotment;
        }
        $advisor->save();
        $this->syncSubscriptionCreditsGranted($advisor, $allotment);
    }

    /**
     * Re-apply hub allotment to every active Excel advisor (used on monthly autorenew).
     *
     * @return int Number of advisors updated
     */
    public function applyToAllActiveAdvisors(Hub $hub): int
    {
        $updated = 0;

        DB::transaction(function () use ($hub, &$updated) {
            $advisors = User::query()
                ->where('is_advisor', true)
                ->where('is_suspended', false)
                ->where('is_discontinued', false)
                ->lockForUpdate()
                ->get();

            foreach ($advisors as $advisor) {
                $this->applyToAdvisor($advisor, $hub, true);
                $updated++;
            }
        });

        return $updated;
    }

    /**
     * Persist Power Admin subscriber-credit setting and keep Functionalities in sync.
     *
     * @param  int|null  $credits  null = unlimited; integer = fixed allotment per period
     */
    public function updateHubSetting(Hub $hub, ?int $credits): Hub
    {
        if ($credits !== null && $credits < 0) {
            throw new \InvalidArgumentException('Subscriber credits cannot be negative.');
        }

        $checklist = $hub->resolvedChecklist();

        if ($credits === null) {
            $hub->subscriber_credits = null;
            $checklist['unlimited_credits'] = true;
            $checklist['paid_credits'] = false;
        } else {
            $hub->subscriber_credits = $credits;
            $checklist['unlimited_credits'] = false;
            $checklist['paid_credits'] = true;
        }

        $hub->checklist = $checklist;
        $hub->save();

        return $hub->fresh();
    }

    /**
     * When Functionalities checklist changes paid/unlimited, keep subscriber_credits aligned.
     *
     * @param  array<string, bool>  $checklist
     */
    public function syncFieldFromChecklist(Hub $hub, array $checklist): void
    {
        $unlimited = ! empty($checklist['unlimited_credits']);
        $paid = ! empty($checklist['paid_credits']);

        if ($unlimited && ! $paid) {
            $hub->subscriber_credits = null;

            return;
        }

        if ($paid && ! $unlimited && $hub->subscriber_credits === null) {
            // Paid mode without a number yet — default to 0 until Power Admin sets an allotment.
            $hub->subscriber_credits = 0;
        }
    }

    private function syncSubscriptionCreditsGranted(User $advisor, int $creditsGranted): void
    {
        $subscription = UserSubscription::query()
            ->where('user_id', $advisor->id)
            ->where('payment_method', 'advisor_import')
            ->whereIn('status', ['active', 'suspended'])
            ->orderByDesc('id')
            ->first();

        if ($subscription) {
            $subscription->credits_granted = $creditsGranted;
            $subscription->save();
        }
    }
}
