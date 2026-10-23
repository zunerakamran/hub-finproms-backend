<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModulePackageItem extends Model
{
    protected $fillable = [
        'module_package_id',
        'module_key',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(ModulePackage::class, 'module_package_id');
    }
}
