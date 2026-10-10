<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubRelease;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class HubCodeUpdateService
{
    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_UP_TO_DATE = 'up_to_date';

    public const STATUS_BEHIND = 'behind';

    public const STATUS_AHEAD = 'ahead';

    public const STATUS_ERROR = 'error';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_REMOTE = 'remote_api';

    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        private readonly HubService $hubs,
    ) {}

    /**
     * Version payload for THIS deploy (public GET /api/version).
     *
     * @return array{
     *   version: string,
     *   backend_version: string,
     *   frontend_version: string,
     *   slug: string,
     *   type: string,
     *   is_control_plane: bool
     * }
     */
    public function localVersionPayload(): array
    {
        $hub = $this->hubs->current();
        $version = $this->normalizeVersion((string) config('hub.version', '0.0.0'));
        $frontend = $this->normalizeVersion((string) config('hub.frontend_version', $version));

        return [
            'version' => $version,
            'backend_version' => $version,
            'frontend_version' => $frontend,
            'slug' => $hub->slug,
            'type' => $hub->type,
            'is_control_plane' => (bool) config('hub.is_control_plane', false),
        ];
    }

    public function latestRelease(): ?HubRelease
    {
        return HubRelease::query()
            ->where('is_latest', true)
            ->orderByDesc('id')
            ->first()
            ?? HubRelease::query()->orderByDesc('id')->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listReleases(int $limit = 50): array
    {
        return HubRelease::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (HubRelease $r) => $r->toAdminArray())
            ->values()
            ->all();
    }

    /**
     * @param  array{version: string, backend_version?: ?string, frontend_version?: ?string, notes?: ?string}  $data
     */
    public function publishRelease(array $data, ?User $user = null): HubRelease
    {
        $version = $this->normalizeVersion($data['version']);
        if ($version === '') {
            throw new \InvalidArgumentException('Version is required.');
        }

        $backend = $this->normalizeVersion($data['backend_version'] ?? $version) ?: $version;
        $frontend = $this->normalizeVersion($data['frontend_version'] ?? $version) ?: $version;

        $release = HubRelease::query()->updateOrCreate(
            ['version' => $version],
            [
                'backend_version' => $backend,
                'frontend_version' => $frontend,
                'notes' => $data['notes'] ?? null,
                'published_by' => $user?->id,
                'published_at' => now(),
            ]
        );

        HubRelease::query()->where('id', '!=', $release->id)->update(['is_latest' => false]);
        $release->forceFill(['is_latest' => true])->save();

        $this->recomputeAllStatuses();

        return $release->fresh();
    }

    /**
     * Poll a hub for its running version and store on the Central registry row.
     *
     * @return array<string, mixed>
     */
    public function refreshHub(Hub $hub): array
    {
        $current = $this->hubs->current();

        try {
            if ($hub->isControlPlane() || $hub->slug === $current->slug) {
                $payload = $this->localVersionPayload();
                $source = self::SOURCE_LOCAL;
            } else {
                $apiUrl = rtrim((string) ($hub->api_url ?: ''), '/');
                if ($apiUrl === '') {
                    $hub->forceFill([
                        'code_version_status' => self::STATUS_ERROR,
                        'code_version_source' => self::SOURCE_REMOTE,
                        'code_version_checked_at' => now(),
                        'code_version_check_error' => 'Set api_url in deploy wiring before refreshing version.',
                    ])->save();

                    return $this->codeUpdatePayload($hub->fresh());
                }

                $verify = (bool) config('services.http_tls_verify', true);
                $response = Http::withOptions(['verify' => $verify])
                    ->timeout(20)
                    ->acceptJson()
                    ->get($apiUrl.'/version');

                if (! $response->successful()) {
                    throw new \RuntimeException(
                        'HTTP '.$response->status().': '.Str::limit($response->body(), 300)
                    );
                }

                $json = $response->json();
                if (! is_array($json)) {
                    throw new \RuntimeException('Invalid JSON from remote /version.');
                }

                $payload = [
                    'version' => $this->normalizeVersion((string) ($json['version'] ?? '')),
                    'backend_version' => $this->normalizeVersion(
                        (string) ($json['backend_version'] ?? $json['version'] ?? '')
                    ),
                    'frontend_version' => $this->normalizeVersion(
                        (string) ($json['frontend_version'] ?? $json['version'] ?? '')
                    ),
                ];
                if ($payload['version'] === '') {
                    throw new \RuntimeException('Remote /version did not return a version.');
                }
                $source = self::SOURCE_REMOTE;
            }

            $this->storeReportedVersions($hub, $payload, $source, null);

            return $this->codeUpdatePayload($hub->fresh());
        } catch (Throwable $e) {
            report($e);
            $hub->forceFill([
                'code_version_status' => self::STATUS_ERROR,
                'code_version_source' => self::SOURCE_REMOTE,
                'code_version_checked_at' => now(),
                'code_version_check_error' => Str::limit($e->getMessage(), 1000),
            ])->save();

            return $this->codeUpdatePayload($hub->fresh());
        }
    }

    /**
     * @return array{refreshed: int, results: list<array<string, mixed>>}
     */
    public function refreshAll(): array
    {
        $results = [];
        $hubs = Hub::query()->orderBy('id')->get();

        foreach ($hubs as $hub) {
            $results[] = [
                'hub_id' => $hub->id,
                'slug' => $hub->slug,
                'code_update' => $this->refreshHub($hub),
            ];
        }

        return [
            'refreshed' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Manually record versions when API poll is unavailable (e.g. FTP-only frontend note).
     *
     * @param  array{version: string, backend_version?: ?string, frontend_version?: ?string}  $data
     * @return array<string, mixed>
     */
    public function markManual(Hub $hub, array $data): array
    {
        $version = $this->normalizeVersion($data['version'] ?? '');
        if ($version === '') {
            throw new \InvalidArgumentException('Version is required.');
        }

        $payload = [
            'version' => $version,
            'backend_version' => $this->normalizeVersion($data['backend_version'] ?? $version) ?: $version,
            'frontend_version' => $this->normalizeVersion($data['frontend_version'] ?? $version) ?: $version,
        ];

        $this->storeReportedVersions($hub, $payload, self::SOURCE_MANUAL, null);

        return $this->codeUpdatePayload($hub->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function overviewPayload(): array
    {
        $latest = $this->latestRelease();
        $hubs = Hub::query()->get();
        $counts = [
            'up_to_date' => 0,
            'behind' => 0,
            'ahead' => 0,
            'unknown' => 0,
            'error' => 0,
        ];

        foreach ($hubs as $hub) {
            $status = $hub->code_version_status ?: self::STATUS_UNKNOWN;
            if (! array_key_exists($status, $counts)) {
                $status = self::STATUS_UNKNOWN;
            }
            $counts[$status]++;
        }

        return [
            'latest_release' => $latest?->toAdminArray(),
            'this_deploy' => $this->localVersionPayload(),
            'counts' => $counts,
            'releases' => $this->listReleases(),
        ];
    }

    /**
     * Admin payload embedded on each hub row.
     *
     * @return array<string, mixed>
     */
    public function codeUpdatePayload(Hub $hub): array
    {
        $latest = $this->latestRelease();
        $status = $hub->code_version_status ?: self::STATUS_UNKNOWN;

        return [
            'reported_version' => $hub->code_version,
            'backend_version' => $hub->code_backend_version,
            'frontend_version' => $hub->code_frontend_version,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'source' => $hub->code_version_source,
            'checked_at' => $hub->code_version_checked_at?->toIso8601String(),
            'error' => $hub->code_version_check_error,
            'latest_version' => $latest?->version,
            'is_latest' => $status === self::STATUS_UP_TO_DATE,
        ];
    }

    public function recomputeStatus(Hub $hub): void
    {
        if (! filled($hub->code_version)) {
            $hub->forceFill([
                'code_version_status' => $hub->code_version_status === self::STATUS_ERROR
                    ? self::STATUS_ERROR
                    : self::STATUS_UNKNOWN,
            ])->save();

            return;
        }

        $latest = $this->latestRelease();
        if (! $latest) {
            $hub->forceFill(['code_version_status' => self::STATUS_UNKNOWN])->save();

            return;
        }

        $cmp = version_compare(
            $this->normalizeVersion((string) $hub->code_version),
            $this->normalizeVersion((string) $latest->version)
        );

        $status = match (true) {
            $cmp === 0 => self::STATUS_UP_TO_DATE,
            $cmp < 0 => self::STATUS_BEHIND,
            default => self::STATUS_AHEAD,
        };

        $hub->forceFill(['code_version_status' => $status])->save();
    }

    public function recomputeAllStatuses(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            if ($hub->code_version_status === self::STATUS_ERROR && ! filled($hub->code_version)) {
                return;
            }
            $this->recomputeStatus($hub);
        });
    }

    /**
     * @param  array{version: string, backend_version?: string, frontend_version?: string}  $payload
     */
    private function storeReportedVersions(Hub $hub, array $payload, string $source, ?string $error): void
    {
        $hub->forceFill([
            'code_version' => $payload['version'],
            'code_backend_version' => $payload['backend_version'] ?? $payload['version'],
            'code_frontend_version' => $payload['frontend_version'] ?? $payload['version'],
            'code_version_source' => $source,
            'code_version_checked_at' => now(),
            'code_version_check_error' => $error,
        ])->save();

        $this->recomputeStatus($hub->fresh());
    }

    private function normalizeVersion(?string $version): string
    {
        return trim((string) $version);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_UP_TO_DATE => 'Up to date',
            self::STATUS_BEHIND => 'Behind latest',
            self::STATUS_AHEAD => 'Ahead of latest',
            self::STATUS_ERROR => 'Check failed',
            default => 'Unknown',
        };
    }
}
