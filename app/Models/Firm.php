<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Firm extends Model
{
    public const CENTRAL_DEFAULT_NAME = 'Central / Network';

    protected $fillable = [
        'name',
        'is_central',
        'compliance_visible_to_own',
        'compliance_visible_to_central',
        'compliance_visible_to_firm_id',
        'head_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_central' => 'boolean',
            'compliance_visible_to_own' => 'boolean',
            'compliance_visible_to_central' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function headUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FirmDocument::class);
    }

    public function documentMemberRights(): HasMany
    {
        return $this->hasMany(FirmDocumentMemberRight::class);
    }

    public function complianceVisibleToFirm(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compliance_visible_to_firm_id');
    }

    public function isCentral(): bool
    {
        return (bool) $this->is_central;
    }

    /**
     * The hub's single Central / Network firm (created if missing).
     */
    public static function central(): self
    {
        $existing = static::query()->where('is_central', true)->first();
        if ($existing) {
            return $existing;
        }

        return static::query()->create([
            'name' => self::CENTRAL_DEFAULT_NAME,
            'is_central' => true,
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => true,
            'compliance_visible_to_firm_id' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(int $usersCount = 0): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_central' => $this->isCentral(),
            'users_count' => $usersCount,
            'head_user_id' => $this->head_user_id ? (int) $this->head_user_id : null,
            'head_user' => $this->headUser
                ? [
                    'id' => (int) $this->headUser->id,
                    'name' => (string) $this->headUser->name,
                    'email' => (string) $this->headUser->email,
                ]
                : null,
            'compliance_visibility' => [
                'visible_to_own' => (bool) $this->compliance_visible_to_own,
                'visible_to_central' => (bool) $this->compliance_visible_to_central,
                'visible_to_firm_id' => $this->compliance_visible_to_firm_id
                    ? (int) $this->compliance_visible_to_firm_id
                    : null,
                'visible_to_firm' => $this->complianceVisibleToFirm
                    ? [
                        'id' => (int) $this->complianceVisibleToFirm->id,
                        'name' => (string) $this->complianceVisibleToFirm->name,
                    ]
                    : null,
            ],
        ];
    }
}
