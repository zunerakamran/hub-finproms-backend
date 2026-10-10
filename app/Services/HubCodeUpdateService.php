<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubRelease;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public const APPLY_IDLE = 'idle';

    public const APPLY_UPDATING = 'updating';

    public const APPLY_APPLIED = 'applied';

    public const APPLY_FAILED = 'failed';

    public function __construct(
        private readonly HubService $hubs,
        private readonly HubCodeApplyService $applier,
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
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
        // Prefer live files/marker (Phase 2 apply updates these without a full reboot).
        $version = $this->readLiveVersion();
        $frontend = $this->readLiveFrontendVersion($version);

        return [
            'version' => $version,
            'backend_version' => $version,
            'frontend_version' => $frontend,
            'slug' => $hub->slug,
            'type' => $hub->type,
            'is_control_plane' => (bool) config('hub.is_control_plane', false),
        ];
    }

    private function readLiveVersion(): string
    {
        $markerPath = storage_path('app/private/code-update-version.json');
        if (is_file($markerPath)) {
            $marker = json_decode((string) file_get_contents($markerPath), true);
            if (is_array($marker) && filled($marker['version'] ?? null)) {
                return $this->normalizeVersion((string) $marker['version']);
            }
        }

        $versionFile = base_path('VERSION');
        if (is_file($versionFile)) {
            $fromFile = $this->normalizeVersion((string) file_get_contents($versionFile));
            if ($fromFile !== '') {
                return $fromFile;
            }
        }

        return $this->normalizeVersion((string) config('hub.version', '0.0.0')) ?: '0.0.0';
    }

    private function readLiveFrontendVersion(string $fallback): string
    {
        $markerPath = storage_path('app/private/code-update-version.json');
        if (is_file($markerPath)) {
            $marker = json_decode((string) file_get_contents($markerPath), true);
            if (is_array($marker) && filled($marker['frontend_version'] ?? null)) {
                return $this->normalizeVersion((string) $marker['frontend_version']);
            }
        }

        $fromEnv = $this->normalizeVersion((string) config('hub.frontend_version', ''));

        return $fromEnv !== '' ? $fromEnv : $fallback;
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

    public function storeArtifacts(
        HubRelease $release,
        ?UploadedFile $backendZip = null,
        ?UploadedFile $frontendZip = null,
    ): HubRelease {
        $dir = 'releases/'.$release->version;
        Storage::disk('local')->makeDirectory($dir);

        if ($backendZip) {
            if ($release->backend_artifact_path) {
                Storage::disk('local')->delete($release->backend_artifact_path);
            }
            $path = $backendZip->storeAs($dir, 'backend.zip', 'local');
            $release->backend_artifact_path = $path;
        }

        if ($frontendZip) {
            if ($release->frontend_artifact_path) {
                Storage::disk('local')->delete($release->frontend_artifact_path);
            }
            $path = $frontendZip->storeAs($dir, 'frontend.zip', 'local');
            $release->frontend_artifact_path = $path;
        }

        $release->save();

        return $release->fresh();
    }

    public function ensureUpdateToken(Hub $hub): string
    {
        if (filled($hub->code_update_token)) {
            return (string) $hub->code_update_token;
        }

        // Prefer existing backup token so content hubs authorize without a second sync.
        if (filled($hub->backup_token)) {
            $hub->forceFill(['code_update_token' => $hub->backup_token])->save();

            return (string) $hub->backup_token;
        }

        $token = Str::random(64);
        $hub->forceFill(['code_update_token' => $token])->save();

        return $token;
    }

    public function absoluteArtifactPath(HubRelease $release, string $kind): ?string
    {
        $relative = $kind === 'frontend'
            ? $release->frontend_artifact_path
            : $release->backend_artifact_path;
        if (! filled($relative)) {
            return null;
        }

        return Storage::disk('local')->path($relative);
    }

    public function artifactDownloadUrl(HubRelease $release, string $kind): ?string
    {
        $relative = $kind === 'frontend'
            ? $release->frontend_artifact_path
            : $release->backend_artifact_path;
        if (! filled($relative)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').'/api/internal/code-updates/artifacts/'
            .$release->id.'/'.$kind;
    }

    /**
     * Apply a release to selected hubs from Central.
     *
     * @param  list<int>  $hubIds
     * @return array{applied: int, failed: int, results: list<array<string, mixed>>}
     */
    public function applyReleaseToHubs(HubRelease $release, array $hubIds): array
    {
        if (! filled($release->backend_artifact_path) && ! filled($release->frontend_artifact_path)) {
            throw new \InvalidArgumentException(
                'Upload a backend and/or frontend zip on this release before applying.'
            );
        }

        $results = [];
        $applied = 0;
        $failed = 0;

        $hubs = Hub::query()->whereIn('id', $hubIds)->orderBy('id')->get();
        foreach ($hubs as $hub) {
            $row = $this->applyReleaseToHub($release, $hub);
            $results[] = $row;
            if (($row['ok'] ?? false) === true) {
                $applied++;
            } else {
                $failed++;
            }
        }

        return [
            'applied' => $applied,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * @return array{hub_id: int, slug: string, ok: bool, message: string, code_update: array<string, mixed>}
     */
    public function applyReleaseToHub(HubRelease $release, Hub $hub): array
    {
        $token = $this->ensureUpdateToken($hub);
        $hub->refresh();

        $hub->forceFill([
            'code_apply_status' => self::APPLY_UPDATING,
            'code_target_version' => $release->version,
            'code_apply_error' => null,
        ])->save();

        // Push token + frontend path so the remote hub can authorize / apply.
        if (! $hub->isControlPlane() && $hub->hasRemoteDatabaseConfigured()) {
            try {
                $this->whiteLabelSync->pushSettings($hub);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $current = $this->hubs->current();
        $backendUrl = $this->artifactDownloadUrl($release, 'backend');
        $frontendUrl = $this->artifactDownloadUrl($release, 'frontend');
        $frontendPath = filled($hub->code_frontend_path)
            ? (string) $hub->code_frontend_path
            : (string) config('hub.code_update_frontend_path', '');

        try {
            if ($hub->isControlPlane() || $hub->slug === $current->slug) {
                // Local apply on Central: use files on disk (avoid HTTP self-call).
                $result = $this->applier->applyFromPayload([
                    'version' => $release->version,
                    'backend_local_path' => $this->absoluteArtifactPath($release, 'backend'),
                    'frontend_local_path' => $this->absoluteArtifactPath($release, 'frontend'),
                    'frontend_path' => $frontendPath !== '' ? $frontendPath : null,
                ]);
            } else {
                $apiUrl = rtrim((string) ($hub->api_url ?: ''), '/');
                if ($apiUrl === '') {
                    throw new \RuntimeException('Set api_url in deploy wiring before applying updates.');
                }
                if ($backendUrl === null && $frontendUrl === null) {
                    throw new \RuntimeException('Release has no downloadable artifacts.');
                }

                $verify = (bool) config('services.http_tls_verify', true);
                $timeout = (int) config('services.hub_code_update.apply_timeout', 600);
                $response = Http::withOptions(['verify' => $verify])
                    ->timeout($timeout)
                    ->withHeaders([
                        'X-Hub-Code-Update-Key' => $token,
                        'X-Hub-Slug' => $hub->slug,
                        'Accept' => 'application/json',
                    ])
                    ->post($apiUrl.'/internal/code-updates/apply', [
                        'version' => $release->version,
                        'backend_download_url' => $backendUrl,
                        'frontend_download_url' => $frontendUrl,
                        'frontend_path' => $frontendPath !== '' ? $frontendPath : null,
                        'auth_token' => $token,
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException(
                        'Remote apply failed: HTTP '.$response->status().' '
                        .Str::limit($response->body(), 500)
                    );
                }

                $result = $response->json() ?: [];
            }

            $hub->forceFill([
                'code_apply_status' => self::APPLY_APPLIED,
                'code_applied_at' => now(),
                'code_apply_error' => null,
                'code_target_version' => $release->version,
            ])->save();

            // Re-read reported version (local marker / remote /version).
            $this->refreshHub($hub->fresh());

            return [
                'hub_id' => $hub->id,
                'slug' => $hub->slug,
                'ok' => true,
                'message' => (string) ($result['message'] ?? 'Applied '.$release->version),
                'code_update' => $this->codeUpdatePayload($hub->fresh()),
            ];
        } catch (Throwable $e) {
            report($e);
            $hub->forceFill([
                'code_apply_status' => self::APPLY_FAILED,
                'code_apply_error' => Str::limit($e->getMessage(), 2000),
            ])->save();

            return [
                'hub_id' => $hub->id,
                'slug' => $hub->slug,
                'ok' => false,
                'message' => $e->getMessage(),
                'code_update' => $this->codeUpdatePayload($hub->fresh()),
            ];
        }
    }

    /**
     * Local apply used by InternalCodeUpdateController on content hubs.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function applyLocally(array $payload): array
    {
        return $this->applier->applyFromPayload($payload);
    }

    public function authorizeUpdateKey(string $provided): bool
    {
        if ($provided === '') {
            return false;
        }

        $hub = $this->hubs->current();
        foreach ([(string) ($hub->code_update_token ?: ''), (string) ($hub->backup_token ?: '')] as $expected) {
            if ($expected !== '' && hash_equals($expected, $provided)) {
                return true;
            }
        }

        $shared = (string) config('services.hub_code_update.secret', '');

        return $shared !== '' && hash_equals($shared, $provided);
    }

    public function authorizeArtifactDownload(string $provided): bool
    {
        if ($provided === '') {
            return false;
        }

        if (! config('hub.is_control_plane')) {
            return false;
        }

        $matched = Hub::query()
            ->where(function ($q) {
                $q->whereNotNull('code_update_token')->where('code_update_token', '!=', '')
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('backup_token')->where('backup_token', '!=', '');
                    });
            })
            ->get()
            ->first(function (Hub $row) use ($provided) {
                foreach ([(string) ($row->code_update_token ?: ''), (string) ($row->backup_token ?: '')] as $expected) {
                    if ($expected !== '' && hash_equals($expected, $provided)) {
                        return true;
                    }
                }

                return false;
            });

        if ($matched) {
            return true;
        }

        $shared = (string) config('services.hub_code_update.secret', '');

        return $shared !== '' && hash_equals($shared, $provided);
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

        $applyStatus = $hub->code_apply_status ?: self::APPLY_IDLE;

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
            'apply_status' => $applyStatus,
            'apply_status_label' => $this->applyStatusLabel($applyStatus),
            'target_version' => $hub->code_target_version,
            'applied_at' => $hub->code_applied_at?->toIso8601String(),
            'apply_error' => $hub->code_apply_error,
            'frontend_path' => $hub->code_frontend_path,
            'update_token_set' => filled($hub->code_update_token) || filled($hub->backup_token),
            'can_apply' => filled($hub->api_url) || $hub->isControlPlane(),
        ];
    }

    private function applyStatusLabel(string $status): string
    {
        return match ($status) {
            self::APPLY_UPDATING => 'Updating…',
            self::APPLY_APPLIED => 'Last apply OK',
            self::APPLY_FAILED => 'Last apply failed',
            default => 'Idle',
        };
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
