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

    /** Hub operator who manages content, types, plans, settings. */
    public const ROLE_CLIENT_ADMIN = 'client_admin';

    public const ROLE_USER = 'user';

    /**
     * @deprecated Use ROLE_CLIENT_ADMIN
     */
    public const ROLE_ADMIN = self::ROLE_CLIENT_ADMIN;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'credits',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits' => 'integer',
        ];
    }

    public function isClientAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_CLIENT_ADMIN, 'admin'], true);
    }

    /**
     * @deprecated Use isClientAdmin()
     */
    public function isAdmin(): bool
    {
        return $this->isClientAdmin();
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

    public function hasPurchased(Post $post): bool
    {
        return $this->purchases()->where('post_id', $post->id)->exists();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->exists();
    }

    /**
     * Users with credits or an active subscription can browse post content.
     * Client admins always can. Guests and zero-credit unsubscribed users cannot.
     */
    public function canViewCatalog(): bool
    {
        if ($this->isClientAdmin()) {
            return true;
        }

        return $this->credits > 0 || $this->hasActiveSubscription();
    }
}
