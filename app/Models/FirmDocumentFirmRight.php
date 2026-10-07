<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmDocumentFirmRight extends Model
{
    protected $fillable = [
        'firm_document_id',
        'grantee_firm_id',
        'can_add',
        'can_view',
        'can_delete',
        'can_archive',
    ];

    protected function casts(): array
    {
        return [
            'can_add' => 'boolean',
            'can_view' => 'boolean',
            'can_delete' => 'boolean',
            'can_archive' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FirmDocument::class, 'firm_document_id');
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
            'firm_document_id' => (int) $this->firm_document_id,
            'grantee_firm_id' => (int) $this->grantee_firm_id,
            'can_add' => (bool) $this->can_add,
            'can_view' => (bool) $this->can_view,
            'can_delete' => (bool) $this->can_delete,
            'can_archive' => (bool) $this->can_archive,
        ];
    }
}
