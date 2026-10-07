<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmDocumentVisibleFirm extends Model
{
    protected $fillable = [
        'owner_firm_id',
        'grantee_firm_id',
    ];

    public function ownerFirm(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'owner_firm_id');
    }

    public function granteeFirm(): BelongsTo
    {
        return $this->belongsTo(Firm::class, 'grantee_firm_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => (int) $this->id,
            'owner_firm_id' => (int) $this->owner_firm_id,
            'grantee_firm_id' => (int) $this->grantee_firm_id,
            'grantee_firm' => $this->relationLoaded('granteeFirm') && $this->granteeFirm
                ? [
                    'id' => (int) $this->granteeFirm->id,
                    'name' => (string) $this->granteeFirm->name,
                ]
                : null,
        ];
    }
}
