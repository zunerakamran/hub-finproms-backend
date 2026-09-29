<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class GeneralComplianceContentType extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'general_compliance_content_types';

    protected $fillable = [
        'name',
        'slug',
    ];

    protected static function booted(): void
    {
        static::saving(function (GeneralComplianceContentType $type) {
            if (blank($type->slug)) {
                $type->slug = Str::slug($type->name);
            }
        });
    }
}
