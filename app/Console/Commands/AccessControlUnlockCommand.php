<?php

namespace App\Console\Commands;

use App\Models\SecurityMonitoringSetting;
use App\Models\UserAccessRestrictionSetting;
use App\Services\AccessRestrictionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency recovery when access rules lock out administrators.
 * Run on the server that owns the hub database (not a public HTTP bypass).
 */
class AccessControlUnlockCommand extends Command
{
    protected $signature = 'access-control:unlock
                            {--hub= : Optional hub id to unlock (default: all hubs)}
                            {--keep-rules : Only switch enforcement to monitor; leave rule flags as-is}
                            {--force : Skip confirmation}';

    protected $description = 'Emergency unlock: disable blocking access rules (or switch them to monitor mode)';

    public function handle(AccessRestrictionService $access): int
    {
        $hubId = $this->option('hub') !== null && $this->option('hub') !== ''
            ? (int) $this->option('hub')
            : null;
        $keepRules = (bool) $this->option('keep-rules');

        if (! $this->option('force')) {
            $target = $hubId ? "hub {$hubId}" : 'ALL hubs';
            $action = $keepRules
                ? 'set enforcement_mode=monitor'
                : 'disable UK-only / geo / IP / VPN gates and set monitor mode';
            if (! $this->confirm("Unlock {$target}: {$action}?")) {
                $this->warn('Cancelled.');

                return self::SUCCESS;
            }
        }

        $hubQuery = SecurityMonitoringSetting::query();
        $userQuery = UserAccessRestrictionSetting::query();
        if ($hubId) {
            $hubQuery->where('hub_id', $hubId);
            $userQuery->where('hub_id', $hubId);
        }

        $hubPayload = $this->unlockPayload($keepRules, (new SecurityMonitoringSetting)->getTable());
        $userPayload = $this->unlockPayload($keepRules, (new UserAccessRestrictionSetting)->getTable());

        $hubUpdated = $hubPayload === [] ? 0 : $hubQuery->update($hubPayload);
        $userUpdated = 0;
        if ($userPayload !== [] && Schema::hasTable((new UserAccessRestrictionSetting)->getTable())) {
            $userUpdated = $userQuery->update($userPayload);
        }

        if ($hubId) {
            $access->forgetSettingsCache($hubId);
            UserAccessRestrictionSetting::query()
                ->where('hub_id', $hubId)
                ->pluck('user_id')
                ->each(fn ($userId) => $access->forgetUserSettingsCache($hubId, (int) $userId));
        } else {
            Cache::flush();
        }

        $this->info("Updated hub settings rows: {$hubUpdated}");
        $this->info("Updated user settings rows: {$userUpdated}");
        $this->info($keepRules
            ? 'Enforcement is now monitor — rules stay configured but will not 403.'
            : 'Blocking gates cleared and enforcement set to monitor. You can sign in and reconfigure.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function unlockPayload(bool $keepRules, string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $payload = [];

        if (Schema::hasColumn($table, 'access_enforcement_mode')) {
            $payload['access_enforcement_mode'] = 'monitor';
        }

        if ($keepRules) {
            return $payload;
        }

        $flags = [
            'access_uk_only' => false,
            'access_uk_cities_enabled' => false,
            'access_restrict_countries_enabled' => false,
            'access_allow_countries_enabled' => false,
            'access_restrict_ips_enabled' => false,
            'access_allow_ips_enabled' => false,
            'access_restrict_vpn_enabled' => false,
            'access_allow_vpn_enabled' => false,
        ];

        foreach ($flags as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $payload[$column] = $value;
            }
        }

        return $payload;
    }
}
