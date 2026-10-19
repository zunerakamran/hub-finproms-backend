<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Apply Central-managed deploy settings onto a content hub:
 * 1) Sync hubs.* columns via WhiteLabelHubSyncService (DB)
 * 2) HTTP call so the hub writes allowlisted keys into its local .env
 */
class HubDeployEnvService
{
    /**
     * Keys that may be written into a content hub .env file.
     *
     * @var list<string>
     */
    public const WRITABLE_ENV_KEYS = [
        'CENTRAL_API_URL',
        'CENTRAL_ALLOWED_RECEIVE_HOSTS',
        'CODE_UPDATE_FRONTEND_PATH',
    ];

    public function __construct(
        private readonly HubService $hubs,
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
        private readonly HubBackupService $backups,
    ) {}

    /**
     * Fill missing Central API URL from this control-plane APP_URL.
     */
    public function ensureCentralApiDefaults(Hub $hub): Hub
    {
        if (! $hub->isContentHub()) {
            return $hub;
        }

        $dirty = false;
        if (! filled($hub->central_api_url)) {
            $hub->central_api_url = rtrim((string) config('app.url'), '/').'/api';
            $dirty = true;
        }

        if (! filled($hub->central_allowed_receive_hosts) && filled($hub->central_api_url)) {
            $host = parse_url((string) $hub->central_api_url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hub->central_allowed_receive_hosts = $host;
                $dirty = true;
            }
        }

        if ($dirty) {
            $hub->save();
        }

        return $hub->fresh() ?? $hub;
    }

    /**
     * Apply DB row values onto Laravel config for this request/process.
     */
    public function applyRuntimeConfig(?Hub $hub = null): void
    {
        try {
            $hub ??= $this->hubs->current();
        } catch (Throwable) {
            return;
        }

        $overrides = [];

        if (filled($hub->central_api_url)) {
            $overrides['services.hub_backup.central_api_url'] = rtrim((string) $hub->central_api_url, '/');
        }
        if (filled($hub->central_allowed_receive_hosts)) {
            $overrides['services.hub_backup.allowed_receive_hosts'] = (string) $hub->central_allowed_receive_hosts;
        }
        if (filled($hub->code_frontend_path)) {
            $overrides['hub.code_update_frontend_path'] = (string) $hub->code_frontend_path;
        }

        if ($overrides !== []) {
            config($overrides);
        }
    }

    /**
     * Values destined for the content hub .env (from this hub registry row).
     *
     * @return array<string, string>
     */
    public function envValuesForHub(Hub $hub): array
    {
        $values = [];
        if (filled($hub->central_api_url)) {
            $values['CENTRAL_API_URL'] = rtrim((string) $hub->central_api_url, '/');
        }
        if (filled($hub->central_allowed_receive_hosts)) {
            $values['CENTRAL_ALLOWED_RECEIVE_HOSTS'] = (string) $hub->central_allowed_receive_hosts;
        }
        if (filled($hub->code_frontend_path)) {
            $values['CODE_UPDATE_FRONTEND_PATH'] = (string) $hub->code_frontend_path;
        }

        return $values;
    }

    /**
     * Write allowlisted keys into this deploy's .env (content hub local apply).
     *
     * @param  array<string, string>  $values
     * @return array{written: list<string>, path: string}
     */
    public function writeLocalEnvFile(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $key = strtoupper(trim((string) $key));
            if (! in_array($key, self::WRITABLE_ENV_KEYS, true)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || preg_match('/[\r\n]/', $value)) {
                continue;
            }
            $clean[$key] = $value;
        }

        if ($clean === []) {
            throw new RuntimeException('No allowlisted deploy env values to write.');
        }

        $path = base_path('.env');
        if (! is_file($path)) {
            throw new RuntimeException('.env file not found on this hub.');
        }
        if (! is_writable($path)) {
            throw new RuntimeException('.env is not writable on this hub. Fix file permissions or set values manually.');
        }

        $contents = (string) file_get_contents($path);
        foreach ($clean as $key => $value) {
            $contents = $this->upsertEnvLine($contents, $key, $value);
        }

        $tmp = $path.'.tmp_'.bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException('Could not write temporary .env file.');
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not replace .env file.');
        }

        return [
            'written' => array_keys($clean),
            'path' => $path,
        ];
    }

    /**
     * From Central: sync DB + ask the content hub to update its .env.
     *
     * @return array{db_synced: bool, env_written: bool, written_keys: list<string>, message: string}
     */
    public function pushToContentHub(Hub $hub): array
    {
        if (! config('hub.is_control_plane')) {
            throw new RuntimeException('Deploy env push can only be initiated from Central.');
        }
        if (! $hub->isContentHub()) {
            throw new RuntimeException('Deploy env push is only for Shared / White-label hubs.');
        }

        $hub = $this->ensureCentralApiDefaults($hub);

        $dbSynced = false;
        if ($hub->hasRemoteDatabaseConfigured()) {
            $this->whiteLabelSync->pushSettings($hub);
            $dbSynced = true;
        }

        $apiUrl = rtrim((string) ($hub->api_url ?: ''), '/');
        if ($apiUrl === '') {
            return [
                'db_synced' => $dbSynced,
                'env_written' => false,
                'written_keys' => [],
                'message' => $dbSynced
                    ? 'Synced deploy settings to the hub database. Set API URL to also write the hub .env file.'
                    : 'Set remote DB wiring and API URL before pushing deploy env.',
            ];
        }

        $token = $this->backups->ensureBackupToken($hub);
        $payload = $this->envValuesForHub($hub);
        if ($payload === []) {
            return [
                'db_synced' => $dbSynced,
                'env_written' => false,
                'written_keys' => [],
                'message' => 'Nothing to write — set Central API URL and/or frontend extract path first.',
            ];
        }

        $verify = (bool) config('services.http_tls_verify', true);
        $response = Http::withOptions(['verify' => $verify])
            ->timeout(30)
            ->withHeaders([
                'X-Hub-Backup-Key' => $token,
                'X-Hub-Slug' => $hub->slug,
                'Accept' => 'application/json',
            ])
            ->post($apiUrl.'/internal/deploy-env/apply', [
                'values' => $payload,
            ]);

        if (! $response->successful()) {
            Log::warning('deploy env push failed', [
                'hub' => $hub->slug,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new RuntimeException(
                'Hub DB '.($dbSynced ? 'synced' : 'not synced').', but .env write failed: HTTP '
                .$response->status().' '.substr($response->body(), 0, 300)
            );
        }

        $json = $response->json() ?: [];

        return [
            'db_synced' => $dbSynced,
            'env_written' => true,
            'written_keys' => is_array($json['written'] ?? null) ? $json['written'] : array_keys($payload),
            'message' => (string) ($json['message'] ?? 'Deploy env applied on hub.'),
        ];
    }

    private function upsertEnvLine(string $contents, string $key, string $value): string
    {
        $escaped = $this->formatEnvValue($value);
        $line = $key.'='.$escaped;
        $pattern = '/^'.preg_quote($key, '/').'\s*=.*$/m';

        if (preg_match($pattern, $contents)) {
            return (string) preg_replace($pattern, $line, $contents, 1);
        }

        return rtrim($contents)."\n".$line."\n";
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\'\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }
}
