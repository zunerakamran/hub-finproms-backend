<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = $hub->resolvedChecklist();
            $checklist = $hub->applyModuleDependencies($checklist);
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['module_shared_hub']);
            $hub->checklist = $checklist;
            $hub->save();
        });
    }
};
