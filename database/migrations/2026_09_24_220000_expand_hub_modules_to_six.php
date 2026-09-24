<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = $hub->resolvedChecklist();
            $stored = is_array($hub->checklist) ? $hub->checklist : [];

            // White Label Hub is type-locked.
            $checklist['module_white_label_hub'] = $hub->isWhiteLabel();

            // Preserve existing posts-library access (was always available before this module).
            if (! array_key_exists('module_social_media_template_library', $stored)) {
                $checklist['module_social_media_template_library'] = true;
            }

            // Hubs that already had Website Compliance keep showcase/template access.
            if (! array_key_exists('module_website_template_library', $stored)) {
                $checklist['module_website_template_library'] = (bool) (
                    $checklist['module_website_compliance'] ?? false
                );
            }

            foreach (Hub::MODULE_KEYS as $moduleKey) {
                if (! array_key_exists($moduleKey, $checklist)) {
                    $checklist[$moduleKey] = (bool) (
                        Hub::defaultChecklist($hub->type)[$moduleKey] ?? false
                    );
                }
            }

            $checklist['module_white_label_hub'] = $hub->isWhiteLabel();

            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset(
                $checklist['module_white_label_hub'],
                $checklist['module_social_media_template_library'],
                $checklist['module_website_template_library']
            );
            $hub->checklist = $checklist;
            $hub->save();
        });
    }
};
