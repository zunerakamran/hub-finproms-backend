<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmDocumentMemberRight extends Model
{
    protected $fillable = [
        'firm_id',
        'firm_document_id',
        'user_id',
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

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FirmDocument::class, 'firm_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFirmWide(): bool
    {
        return $this->firm_document_id === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'firm_id' => (int) $this->firm_id,
            'firm_document_id' => $this->firm_document_id ? (int) $this->firm_document_id : null,
            'user_id' => (int) $this->user_id,
            'user' => $this->user ? [
                'id' => (int) $this->user->id,
                'name' => (string) $this->user->name,
                'email' => (string) $this->user->email,
            ] : null,
            'can_add' => (bool) $this->can_add,
            'can_view' => (bool) $this->can_view,
            'can_delete' => (bool) $this->can_delete,
            'can_archive' => (bool) $this->can_archive,
        ];
    }
}
