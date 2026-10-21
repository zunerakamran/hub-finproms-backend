<?php

namespace App\Support;

use App\Models\Hub;
use App\Services\HubService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Purpose-scoped auth for Central ↔ hub /internal/* callbacks.
 *
 * Compromising the backup token must not authorize restore, GC file bridge,
 * or deploy-env writes (and vice versa). Code updates already use a separate
 * code_update_token + X-Hub-Code-Update-Key.
 */
class HubInternalAuth
{
    public const PURPOSE_BACKUP = 'backup';

    public const PURPOSE_RESTORE = 'restore';

    public const PURPOSE_GC_FILE = 'gc_file';

    public const PURPOSE_DEPLOY_ENV = 'deploy_env';

    public const HEADER_BACKUP = 'X-Hub-Backup-Key';

    public const HEADER_RESTORE = 'X-Hub-Restore-Key';

    public const HEADER_GC_FILE = 'X-Hub-Gc-File-Key';

    public const HEADER_DEPLOY_ENV = 'X-Hub-Deploy-Env-Key';

    /**
     * @return list<string>
     */
    public static function purposes(): array
    {
        return [
            self::PURPOSE_BACKUP,
            self::PURPOSE_RESTORE,
            self::PURPOSE_GC_FILE,
            self::PURPOSE_DEPLOY_ENV,
        ];
    }

    public static function headerFor(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_BACKUP => self::HEADER_BACKUP,
            self::PURPOSE_RESTORE => self::HEADER_RESTORE,
            self::PURPOSE_GC_FILE => self::HEADER_GC_FILE,
            self::PURPOSE_DEPLOY_ENV => self::HEADER_DEPLOY_ENV,
            default => throw new \InvalidArgumentException('Unknown hub internal purpose: '.$purpose),
        };
    }

    public static function hubTokenColumn(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_BACKUP => 'backup_token',
            self::PURPOSE_RESTORE => 'restore_token',
            self::PURPOSE_GC_FILE => 'gc_file_token',
            self::PURPOSE_DEPLOY_ENV => 'deploy_env_token',
            default => throw new \InvalidArgumentException('Unknown hub internal purpose: '.$purpose),
        };
    }

    public static function sharedSecretConfigKey(string $purpose): string
    {
        return match ($purpose) {
            self::PURPOSE_BACKUP => 'services.hub_backup.secret',
            self::PURPOSE_RESTORE => 'services.hub_backup.restore_secret',
            self::PURPOSE_GC_FILE => 'services.hub_backup.gc_file_secret',
            self::PURPOSE_DEPLOY_ENV => 'services.hub_backup.deploy_env_secret',
            default => throw new \InvalidArgumentException('Unknown hub internal purpose: '.$purpose),
        };
    }

    /**
     * Optional CIDR/IP allowlist for /internal/* (empty = no IP restriction).
     */
    public static function ipAllowed(Request $request): bool
    {
        $raw = trim((string) config('services.hub_backup.internal_allowed_ips', ''));
        if ($raw === '') {
            return true;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($allowed === []) {
            return true;
        }

        $ip = (string) $request->ip();
        foreach ($allowed as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && self::ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function authorizeCurrentHub(Request $request, string $purpose): bool
    {
        if (! self::ipAllowed($request)) {
            return false;
        }

        $provided = (string) $request->header(self::headerFor($purpose), '');
        if ($provided === '') {
            return false;
        }

        $hub = app(HubService::class)->current();
        $column = self::hubTokenColumn($purpose);
        $expected = (string) ($hub->{$column} ?: '');
        if ($expected !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        $shared = (string) config(self::sharedSecretConfigKey($purpose), '');

        return $shared !== '' && hash_equals($shared, $provided);
    }

    /**
     * Central receive: match provided backup key to a hub token or shared backup secret.
     */
    public static function authorizeIncomingBackup(Request $request, string $slug): bool
    {
        if (! self::ipAllowed($request)) {
            return false;
        }

        $provided = (string) $request->header(self::HEADER_BACKUP, '');
        if ($provided === '') {
            return false;
        }

        $hub = $slug !== ''
            ? Hub::query()->where('slug', $slug)->first()
            : null;
        if ($hub && filled($hub->backup_token) && hash_equals((string) $hub->backup_token, $provided)) {
            return true;
        }

        $matched = Hub::query()
            ->whereNotNull('backup_token')
            ->where('backup_token', '!=', '')
            ->get()
            ->first(fn (Hub $row) => hash_equals((string) $row->backup_token, $provided));
        if ($matched) {
            return true;
        }

        $shared = (string) config('services.hub_backup.secret', '');

        return $shared !== '' && hash_equals($shared, $provided);
    }

    /**
     * Resolve the key Central should send for this purpose (per-hub token preferred).
     */
    public static function ensureToken(Hub $hub, string $purpose): string
    {
        $column = self::hubTokenColumn($purpose);
        if (filled($hub->{$column})) {
            return (string) $hub->{$column};
        }

        $token = Str::random(64);
        $hub->forceFill([$column => $token])->save();

        return $token;
    }

    /**
     * Ensure backup + restore + GC + deploy-env tokens exist (idempotent).
     *
     * @return array<string, string>
     */
    public static function ensureAllTokens(Hub $hub): array
    {
        $out = [];
        foreach (self::purposes() as $purpose) {
            $out[$purpose] = self::ensureToken($hub, $purpose);
        }

        return $out;
    }

    public static function tokenOrShared(Hub $hub, string $purpose): string
    {
        $column = self::hubTokenColumn($purpose);
        $token = (string) ($hub->{$column} ?: '');
        if ($token !== '') {
            return $token;
        }

        return (string) config(self::sharedSecretConfigKey($purpose), '');
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($subnet === null || $mask === null || ! is_numeric($mask)) {
            return false;
        }
        $mask = (int) $mask;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false || $mask < 0 || $mask > 32) {
            return false;
        }

        $maskLong = $mask === 0 ? 0 : (-1 << (32 - $mask));

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
