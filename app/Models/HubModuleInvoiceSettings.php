<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class HubModuleInvoiceSettings extends Model
{
    public const FORMAT_STANDARD = 'standard';

    public const FORMAT_COMPACT = 'compact';

    public const FORMAT_DETAILED = 'detailed';

    public const FORMATS = [
        self::FORMAT_STANDARD,
        self::FORMAT_COMPACT,
        self::FORMAT_DETAILED,
    ];

    protected $table = 'hub_module_invoice_settings';

    protected $fillable = [
        'hub_id',
        'network_pays_all',
        'firms_pay_own',
        'users_pay_own',
        'network_resells',
        'reseller_logo_path',
        'reseller_logo_name',
        'reseller_invoice_text',
        'reseller_invoice_format',
    ];

    protected function casts(): array
    {
        return [
            'network_pays_all' => 'boolean',
            'firms_pay_own' => 'boolean',
            'users_pay_own' => 'boolean',
            'network_resells' => 'boolean',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function resellerLogoUrl(): ?string
    {
        if (! $this->reseller_logo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->reseller_logo_path);
    }
}
