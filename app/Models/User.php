<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Platform developers / us — shared hub Power Admin control plane. */
    public const ROLE_POWER_ADMIN = 'power_admin';

    /** Shared hub operator (FinProms). */
    public const ROLE_FINPROMS_ADMIN = 'finproms_admin';

    /** White-labelled hub operator. */
    public const ROLE_CLIENT_ADMIN = 'client_admin';

    /** Mid-level hub staff. */
    public const ROLE_MANAGER = 'manager';

    /** Compliance / content approver. */
    public const ROLE_APPROVER = 'approver';

    /** White-label advisor (subscriber, typically unlimited credits). */
    public const ROLE_ADVISOR = 'advisor';

    /** General user / subscriber. */
    public const ROLE_USER = 'user';

    /**
     * @deprecated Use ROLE_CLIENT_ADMIN
     */
    public const ROLE_ADMIN = self::ROLE_CLIENT_ADMIN;

    /**
     * Roles that may use the hub-admin shell (client-admin routes), subject to hub checklist.
     *
     * @var list<string>
     */
    public const HUB_ADMIN_ROLES = [
        self::ROLE_FINPROMS_ADMIN,
        self::ROLE_CLIENT_ADMIN,
        self::ROLE_MANAGER,
        'admin', // legacy
    ];

    /**
     * @var array<string, string>
     */
    public const ROLE_LABELS = [
        self::ROLE_POWER_ADMIN => 'Power Admin',
        self::ROLE_FINPROMS_ADMIN => 'FinProms Admin',
        self::ROLE_CLIENT_ADMIN => 'Client Admin',
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_APPROVER => 'Approver',
        self::ROLE_ADVISOR => 'Advisor',
        self::ROLE_USER => 'User',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'credits',
        'is_advisor',
        'has_unlimited_credits',
        'is_suspended',
        'is_discontinued',
        'discontinued_at',
        'stripe_customer_id',
        'stripe_payment_method_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'stripe_payment_method_id',
    ];

    protected $appends = [
        'role_label',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits' => 'integer',
            'is_advisor' => 'boolean',
            'has_unlimited_credits' => 'boolean',
            'is_suspended' => 'boolean',
            'is_discontinued' => 'boolean',
            'discontinued_at' => 'datetime',
        ];
    }

    public function isSuspended(): bool
    {
        return (bool) $this->is_suspended;
    }

    public function isDiscontinued(): bool
    {
        return (bool) $this->is_discontinued;
    }

    /**
     * Active Excel advisors (not hub-suspended, not permanently discontinued).
     */
    public function isActiveAdvisor(): bool
    {
        return $this->isAdvisor() && ! $this->isSuspended() && ! $this->isDiscontinued();
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLE_LABELS[$this->role] ?? (string) $this->role;
    }

    public function isPowerAdmin(): bool
    {
        return $this->role === self::ROLE_POWER_ADMIN;
    }

    public function isFinpromsAdmin(): bool
    {
        return $this->role === self::ROLE_FINPROMS_ADMIN;
    }

    public function isClientAdmin(): bool
    {
        // Hub-admin shell access (FinProms admin, WL client admin, manager, legacy admin).
        return in_array($this->role, self::HUB_ADMIN_ROLES, true);
    }

    public function isWhiteLabelClientAdmin(): bool
    {
        return $this->role === self::ROLE_CLIENT_ADMIN || $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isApprover(): bool
    {
        return $this->role === self::ROLE_APPROVER;
    }

    public function isAdvisor(): bool
    {
        return $this->role === self::ROLE_ADVISOR || (bool) $this->is_advisor;
    }

    /**
     * Staff and Excel-invited advisors may sign in when the hub is invite-only.
     * General members (self-registered users) may not. Suspended / discontinued advisors may not.
     */
    public function mayLoginOnInviteOnlyHub(): bool
    {
        if ($this->isSuspended() || $this->isDiscontinued()) {
            return false;
        }

        return $this->isPowerAdmin()
            || $this->isClientAdmin()
            || $this->isApprover()
            || $this->isAdvisor();
    }

    /**
     * @deprecated Use isClientAdmin()
     */
    public function isAdmin(): bool
    {
        return $this->isClientAdmin();
    }

    /**
     * Per-user unlimited credits (white-label advisors), optionally combined with hub checklist.
     * Suspended / discontinued advisors never receive unlimited credits.
     */
    public function hasUnlimitedCredits(bool $hubAllowsUnlimited = true): bool
    {
        if ($this->isSuspended() || $this->isDiscontinued()) {
            return false;
        }

        if ($this->has_unlimited_credits) {
            return true;
        }

        return $hubAllowsUnlimited && $this->isAdvisor();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'created_by');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(PostPurchase::class);
    }

    public function bundlePurchases(): HasMany
    {
        return $this->hasMany(BundlePurchase::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function hasPurchased(Post $post): bool
    {
        return $this->purchases()->where('post_id', $post->id)->exists();
    }

    public function hasPurchasedBundle(Bundle $bundle): bool
    {
        return $this->bundlePurchases()->where('bundle_id', $bundle->id)->exists();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription() !== null;
    }

    public function activeSubscription(): ?UserSubscription
    {
        if ($this->isSuspended() || $this->isDiscontinued()) {
            return null;
        }

        return $this->subscriptions()
            ->with('plan')
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest('starts_at')
            ->first();
    }

    public function activePlan(): ?SubscriptionPlan
    {
        return $this->activeSubscription()?->plan;
    }

    /**
     * Which content metrics this user is allowed to see.
     * Hub/Power admins and unlimited-credit advisors see all three.
     * Subscribers follow their plan flags (Basic/Standard/Premium defaults).
     *
     * @return array{reach: bool, views: bool, buys: bool}
     */
    public function contentMetricVisibility(): array
    {
        if ($this->isPowerAdmin() || $this->isClientAdmin()) {
            return ['reach' => true, 'views' => true, 'buys' => true];
        }

        $plan = $this->activePlan();
        if ($plan) {
            return $plan->visible_metrics;
        }

        // Unlimited advisors without a named plan get full metrics (Premium-equivalent).
        if ($this->hasUnlimitedCredits()) {
            return ['reach' => true, 'views' => true, 'buys' => true];
        }

        return ['reach' => false, 'views' => false, 'buys' => false];
    }

    public function canViewCatalog(): bool
    {
        return true;
    }
}
