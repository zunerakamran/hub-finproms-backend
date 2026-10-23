<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

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

    /**
     * @deprecated Prefer complianceAuthorityFirms() — kept for legacy single-peer column.
     */
    public function complianceVisibleToFirm(): BelongsTo
    {
        return $this->belongsTo(self::class, 'compliance_visible_to_firm_id');
    }

    /**
     * Firms that may review / assign / report / audit this firm's compliance work
     * (Delegated compliance access — one-way).
     */
    public function complianceAuthorityFirms(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'firm_compliance_authorities',
            'subject_firm_id',
            'authority_firm_id'
        )->withTimestamps();
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
     * Sync Delegated compliance access authorities and mirror legacy columns.
     *
     * @param  list<int|string>  $authorityFirmIds
     */
    public function syncComplianceAuthorities(array $authorityFirmIds): void
    {
        if (! Schema::hasTable('firm_compliance_authorities')) {
            return;
        }

        $subjectId = (int) $this->id;
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $authorityFirmIds),
            static fn (int $id) => $id > 0 && $id !== $subjectId
        )));

        $validIds = static::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $this->complianceAuthorityFirms()->sync($validIds);

        $centralId = static::query()->where('is_central', true)->value('id');
        $centralId = $centralId ? (int) $centralId : null;
        $hasCentral = $centralId !== null && in_array($centralId, $validIds, true);
        $peerIds = array_values(array_filter(
            $validIds,
            static fn (int $id) => $centralId === null || $id !== $centralId
        ));

        $this->forceFill([
            'compliance_visible_to_central' => $hasCentral,
            'compliance_visible_to_firm_id' => $peerIds[0] ?? null,
        ])->save();

        $this->unsetRelation('complianceAuthorityFirms');
        $this->unsetRelation('complianceVisibleToFirm');
    }

    /**
     * Authority firm IDs for this subject firm (pivot, with legacy fallback).
     *
     * @return list<int>
     */
    public function complianceAuthorityFirmIds(): array
    {
        if (Schema::hasTable('firm_compliance_authorities')) {
            if ($this->relationLoaded('complianceAuthorityFirms')) {
                return $this->complianceAuthorityFirms
                    ->pluck('id')
                    ->map(static fn ($id) => (int) $id)
                    ->values()
                    ->all();
            }

            return $this->complianceAuthorityFirms()
                ->pluck('firms.id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $ids = [];
        if ($this->compliance_visible_to_central) {
            $centralId = static::query()->where('is_central', true)->value('id');
            if ($centralId) {
                $ids[] = (int) $centralId;
            }
        }
        if ($this->compliance_visible_to_firm_id) {
            $ids[] = (int) $this->compliance_visible_to_firm_id;
        }

        return array_values(array_unique(array_filter(
            $ids,
            fn (int $id) => $id !== (int) $this->id
        )));
    }

    /**
     * Delegated compliance access payload for APIs / request serializers.
     *
     * @return array<string, mixed>
     */
    public function complianceVisibilityForApi(): array
    {
        if (Schema::hasTable('firm_compliance_authorities')
            && ! $this->relationLoaded('complianceAuthorityFirms')
        ) {
            $this->load(['complianceAuthorityFirms:id,name,is_central']);
        }

        $authorityFirms = $this->relationLoaded('complianceAuthorityFirms')
            ? $this->complianceAuthorityFirms
            : collect();

        if ($authorityFirms->isEmpty() && ! Schema::hasTable('firm_compliance_authorities')) {
            if ($this->compliance_visible_to_central) {
                $central = static::query()->where('is_central', true)->first(['id', 'name', 'is_central']);
                if ($central && (int) $central->id !== (int) $this->id) {
                    $authorityFirms->push($central);
                }
            }
            if ($this->complianceVisibleToFirm
                && (int) $this->complianceVisibleToFirm->id !== (int) $this->id
            ) {
                $authorityFirms->push($this->complianceVisibleToFirm);
            }
        }

        $authorityFirms = $authorityFirms->unique('id')->values();
        $centralAuthority = $authorityFirms->first(fn ($f) => (bool) $f->is_central);
        $firstPeer = $authorityFirms->first(fn ($f) => ! (bool) $f->is_central);

        return [
            'visible_to_own' => (bool) $this->compliance_visible_to_own,
            'authority_firm_ids' => $authorityFirms
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all(),
            'authority_firms' => $authorityFirms
                ->map(static fn ($f) => [
                    'id' => (int) $f->id,
                    'name' => (string) $f->name,
                    'is_central' => (bool) $f->is_central,
                ])
                ->values()
                ->all(),
            'visible_to_central' => $centralAuthority !== null,
            'visible_to_firm_id' => $firstPeer ? (int) $firstPeer->id : null,
            'visible_to_firm' => $firstPeer
                ? [
                    'id' => (int) $firstPeer->id,
                    'name' => (string) $firstPeer->name,
                ]
                : null,
        ];
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
            'compliance_visibility' => $this->complianceVisibilityForApi(),
        ];
    }
}
