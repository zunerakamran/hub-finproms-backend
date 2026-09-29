<?php

namespace App\Console\Commands;

use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\HubService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Run on a Shared / White-label CONTENT hub after Central takes over the control plane.
 * Removes leftover hub-registry rows and turns off remote-control capabilities on this DB.
 */
class StripControlPlaneFromContentHubCommand extends Command
{
    protected $signature = 'hub:strip-control-plane
                            {--force : Skip confirmation}
                            {--keep-registry : Do not delete other hubs rows (only disable control caps)}';

    protected $description = 'Strip Central/control-plane data from this Shared or White-label content hub database';

    public function handle(HubService $hubs): int
    {
        $current = $hubs->current();

        if ($current->isControlPlane() || $current->isCentral()) {
            $this->error('Refusing to run on a control-plane / Central Hub deploy.');
            $this->line('This command is only for Shared or White-label CONTENT hub databases.');

            return self::FAILURE;
        }

        if (! $current->isContentHub()) {
            $this->error('Current hub is not a content hub (shared / white_label).');

            return self::FAILURE;
        }

        $this->info('Current content hub: '.$current->name.' ('.$current->slug.', type='.$current->type.')');

        $otherHubs = Hub::query()->where('id', '!=', $current->id)->get(['id', 'name', 'slug', 'type']);
        $this->line('Other hub registry rows on this DB: '.$otherHubs->count());
        foreach ($otherHubs as $hub) {
            $this->line('  - #'.$hub->id.' '.$hub->slug.' ('.$hub->type.')');
        }

        if (! $this->option('force') && ! $this->confirm('Strip control-plane leftovers from this content hub DB?', true)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($current, $otherHubs) {
            // 1) Disable remote-control capability on this hub checklist + role matrix.
            $checklist = is_array($current->checklist) ? $current->checklist : [];
            $checklist['dashboard_control_white_label_hubs'] = false;
            if ($current->isShared()) {
                $checklist['receive_content_from_shared'] = true;
            }
            $current->checklist = $checklist;

            $roleCaps = is_array($current->role_capabilities) ? $current->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                $caps[ActingHubService::CAPABILITY] = false;
                $roleCaps[$role] = $caps;
            }
            $current->role_capabilities = $roleCaps;
            $current->save();

            // 2) Clear acting-hub pointers.
            if (Schema::hasColumn('users', 'acting_hub_id')) {
                User::query()->whereNotNull('acting_hub_id')->update(['acting_hub_id' => null]);
            }

            // 3) Remove foreign hub registry rows (they belong on Central only).
            if (! $this->option('keep-registry') && $otherHubs->isNotEmpty()) {
                $ids = $otherHubs->pluck('id')->all();
                Hub::query()->whereIn('id', $ids)->delete();
            }
        });

        $hubs->forgetCurrentCache();

        $this->info('Done. This DB now only holds content-hub data for "'.$current->slug.'".');
        $this->line('Ensure .env has:');
        $this->line('  HUB_SLUG='.$current->slug);
        if ($current->isShared()) {
            $this->line('  HUB_TYPE=shared');
        }
        $this->line('  HUB_IS_CONTROL_PLANE=false');
        $this->line('Register this hub on Central Hub Controller with remote DB credentials.');

        return self::SUCCESS;
    }
}
