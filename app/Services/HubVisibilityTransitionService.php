<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HubVisibilityTransitionService
{
    /**
     * Behaviour flags applied when a hub becomes public (private invite-only off).
     *
     * @var array<string, bool>
     */
    public const PUBLIC_MODE_FLAGS = [
        'public_subscribe' => true,
        'private_invite_only' => false,
        'paid_credits' => true,
        'unlimited_credits' => false,
        'one_off_purchase' => true,
        'advisor_subscriber_billing' => false,
    ];

    /**
     * Behaviour flags applied when a hub becomes private invite-only.
     *
     * @var array<string, bool>
     */
    public const PRIVATE_MODE_FLAGS = [
        'public_subscribe' => false,
        'private_invite_only' => true,
        'paid_credits' => false,
        'unlimited_credits' => true,
        'one_off_purchase' => false,
        'advisor_subscriber_billing' => true,
    ];

    public function __construct(
        private readonly AdvisorBillingService $advisorBilling,
        private readonly SubscriberCreditsService $subscriberCredits
    ) {}

    /**
     * @param  array<string, bool>  $before
     * @param  array<string, bool>  $after
     */
    public function detectTransition(array $before, array $after): ?string
    {
        $wasPrivate = ! empty($before['private_invite_only']);
        $isPrivate = ! empty($after['private_invite_only']);
        $wasPublic = ! empty($before['public_subscribe']);
        $isPublic = ! empty($after['public_subscribe']);

        if ($wasPrivate && ! $isPrivate && $isPublic) {
            return 'private_to_public';
        }

        if ((! $wasPrivate && $isPrivate) || ($wasPublic && ! $isPublic && $isPrivate)) {
            return 'public_to_private';
        }

        return null;
    }

    /**
     * Cascade public/private-related functionality flags onto the merged checklist.
     *
     * @param  array<string, bool>  $checklist
     * @return array<string, bool>
     */
    public function applyModeFlags(array $checklist, ?string $transition): array
    {
        if ($transition === 'private_to_public') {
            return array_merge($checklist, self::PUBLIC_MODE_FLAGS);
        }

        if ($transition === 'public_to_private') {
            return array_merge($checklist, self::PRIVATE_MODE_FLAGS);
        }

        return $checklist;
    }

    /**
     * Suspend Excel advisors, revoke sessions, and stop advisor auto-renew.
     *
     * @return array{advisors_suspended: int, auto_renew_stopped: bool}
     */
    public function applyPrivateToPublic(Hub $hub): array
    {
        $suspendedUsers = [];

        DB::transaction(function () use (&$suspendedUsers) {
            $advisors = User::query()
                ->where('is_advisor', true)
                ->where('is_suspended', false)
                ->where('is_discontinued', false)
                ->get();

            foreach ($advisors as $advisor) {
                $advisor->is_suspended = true;
                $advisor->save();
                $advisor->tokens()->delete();

                UserSubscription::query()
                    ->where('user_id', $advisor->id)
                    ->where('payment_method', 'advisor_import')
                    ->where('status', 'active')
                    ->update(['status' => 'suspended']);

                $suspendedUsers[] = $advisor->fresh();
            }
        });

        $this->advisorBilling->stopAutoRenewForHub($hub);

        foreach ($suspendedUsers as $advisor) {
            app(FunctionalMailService::class)->advisorSuspended($advisor, $hub);
        }

        return [
            'advisors_suspended' => count($suspendedUsers),
            'auto_renew_stopped' => true,
        ];
    }

    /**
     * Reactivate advisors previously suspended when the hub left private mode.
     */
    public function applyPublicToPrivate(Hub $hub): int
    {
        $reactivatedUsers = [];

        DB::transaction(function () use ($hub, &$reactivatedUsers) {
            $advisors = User::query()
                ->where('is_advisor', true)
                ->where('is_suspended', true)
                ->where('is_discontinued', false)
                ->get();

            foreach ($advisors as $advisor) {
                $advisor->is_suspended = false;
                $advisor->role = User::ROLE_USER;
                $advisor->save();

                $this->ensureAdvisorSubscription($advisor);
                $this->subscriberCredits->applyToAdvisor($advisor->fresh(), $hub, true);
                $reactivatedUsers[] = $advisor->fresh();
            }
        });

        foreach ($reactivatedUsers as $advisor) {
            app(FunctionalMailService::class)->advisorReactivated($advisor);
        }

        return count($reactivatedUsers);
    }

    /**
     * Run side effects for a detected transition after the checklist is saved.
     *
     * @return array{
     *   type: ?string,
     *   advisors_suspended: int,
     *   advisors_reactivated: int,
     *   auto_renew_stopped: bool
     * }
     */
    public function runSideEffects(Hub $hub, ?string $transition): array
    {
        $result = [
            'type' => $transition,
            'advisors_suspended' => 0,
            'advisors_reactivated' => 0,
            'auto_renew_stopped' => false,
        ];

        if ($transition === 'private_to_public') {
            $applied = $this->applyPrivateToPublic($hub);
            $result['advisors_suspended'] = $applied['advisors_suspended'];
            $result['auto_renew_stopped'] = $applied['auto_renew_stopped'];
        } elseif ($transition === 'public_to_private') {
            $result['advisors_reactivated'] = $this->applyPublicToPrivate($hub);
        }

        return $result;
    }

    private function ensureAdvisorSubscription(User $user): void
    {
        $existing = UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('payment_method', 'advisor_import')
            ->whereIn('status', ['active', 'suspended', 'discontinued'])
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->status = 'active';
            $existing->payment_status = 'paid';
            $existing->ends_at = null;
            $existing->save();

            return;
        }

        UserSubscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => null,
            'credits_granted' => 0,
            'amount_paid' => 0,
            'status' => 'active',
            'payment_method' => 'advisor_import',
            'payment_status' => 'paid',
            'payment_reference' => 'ADV-'.Str::upper(Str::random(8)),
            'starts_at' => now(),
            'ends_at' => null,
        ]);
    }
}
