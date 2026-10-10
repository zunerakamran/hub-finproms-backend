<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Apply a release package on THIS deploy (extract zips + migrate).
 * Invoked locally from Central or via /internal/code-updates/apply on content hubs.
 */
class HubCodeApplyService
{
    /**
     * Paths / prefixes never overwritten by a backend release zip.
     *
     * @var list<string>
     */
    private const BACKEND_SKIP = [
        '.env',
        '.env.backup',
        '.env.production',
        '.env.local',
        'storage/app',
        'storage/framework',
        'storage/logs',
        'bootstrap/cache',
        'database/database.sqlite',
        '.git',
        'node_modules',
    ];

    /**
     * @param  array{
     *   version: string,
     *   backend_download_url?: ?string,
     *   frontend_download_url?: ?string,
     *   backend_local_path?: ?string,
     *   frontend_local_path?: ?string,
     *   frontend_path?: ?string,
     *   auth_token?: ?string
     * }  $payload
     * @return array{version: string, backend_applied: bool, frontend_applied: bool, migrated: bool, message: string}
     */
    public function applyFromPayload(array $payload): array
    {
        @set_time_limit(600);

        $version = trim((string) ($payload['version'] ?? ''));
        if ($version === '') {
            throw new RuntimeException('Release version is required.');
        }

        $backendUrl = trim((string) ($payload['backend_download_url'] ?? ''));
        $frontendUrl = trim((string) ($payload['frontend_download_url'] ?? ''));
        $backendLocal = trim((string) ($payload['backend_local_path'] ?? ''));
        $frontendLocal = trim((string) ($payload['frontend_local_path'] ?? ''));
        $frontendPath = trim((string) ($payload['frontend_path'] ?? ''));
        $authToken = (string) ($payload['auth_token'] ?? '');

        if ($backendUrl === '' && $frontendUrl === '' && $backendLocal === '' && $frontendLocal === '') {
            throw new RuntimeException('Release has no backend or frontend artifact to apply.');
        }

        $tmpDir = storage_path('app/private/code-updates/tmp_'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($tmpDir);

        $backendApplied = false;
        $frontendApplied = false;
        $migrated = false;

        try {
            if ($backendLocal !== '' || $backendUrl !== '') {
                $backendZip = $tmpDir.DIRECTORY_SEPARATOR.'backend.zip';
                if ($backendLocal !== '') {
                    if (! is_file($backendLocal)) {
                        throw new RuntimeException('Backend artifact missing on disk.');
                    }
                    File::copy($backendLocal, $backendZip);
                } else {
                    $this->downloadTo($backendUrl, $backendZip, $authToken);
                }
                $this->extractBackendZip($backendZip);
                $this->writeVersionFiles($version, $version);
                $backendApplied = true;
            }

            if ($frontendLocal !== '' || $frontendUrl !== '') {
                $path = $frontendPath !== ''
                    ? $frontendPath
                    : (string) config('hub.code_update_frontend_path', '');
                if ($path === '') {
                    throw new RuntimeException(
                        'Frontend artifact present but no extract path set (hub code_frontend_path or CODE_UPDATE_FRONTEND_PATH).'
                    );
                }
                $frontendZip = $tmpDir.DIRECTORY_SEPARATOR.'frontend.zip';
                if ($frontendLocal !== '') {
                    if (! is_file($frontendLocal)) {
                        throw new RuntimeException('Frontend artifact missing on disk.');
                    }
                    File::copy($frontendLocal, $frontendZip);
                } else {
                    $this->downloadTo($frontendUrl, $frontendZip, $authToken);
                }
                $this->extractFrontendZip($frontendZip, $path);
                if (! $backendApplied) {
                    $this->writeVersionFiles(
                        (string) config('hub.version', $version),
                        $version
                    );
                } else {
                    $this->writeVersionFiles($version, $version);
                }
                $frontendApplied = true;
            }

            if ($backendApplied) {
                try {
                    Artisan::call('migrate', ['--force' => true]);
                    $migrated = true;
                } catch (Throwable $e) {
                    throw new RuntimeException('Files applied but migrate failed: '.$e->getMessage(), 0, $e);
                }

                try {
                    Artisan::call('config:clear');
                    Artisan::call('route:clear');
                    Artisan::call('view:clear');
                    Artisan::call('cache:clear');
                } catch (Throwable) {
                    // Non-fatal on shared hosting.
                }
            }

            return [
                'version' => $version,
                'backend_applied' => $backendApplied,
                'frontend_applied' => $frontendApplied,
                'migrated' => $migrated,
                'message' => 'Release '.$version.' applied on this hub.',
            ];
        } finally {
            File::deleteDirectory($tmpDir);
        }
    }

    private function downloadTo(string $url, string $dest, string $authToken): void
    {
        $verify = (bool) config('services.http_tls_verify', true);
        $timeout = (int) config('services.hub_code_update.download_timeout', 600);

        $response = Http::withOptions(['verify' => $verify])
            ->timeout($timeout)
            ->withHeaders([
                'Accept' => 'application/zip, application/octet-stream',
                'X-Hub-Code-Update-Key' => $authToken,
            ])
            ->sink($dest)
            ->get($url);

        if (! $response->successful() || ! is_file($dest) || filesize($dest) < 32) {
            @unlink($dest);
            throw new RuntimeException(
                'Failed to download artifact from '.$url.' (HTTP '.$response->status().')'
            );
        }
    }

    private function extractBackendZip(string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open backend zip.');
        }

        $root = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $name = str_replace('\\', '/', $name);
                if (str_ends_with($name, '/')) {
                    continue;
                }

                $relative = $this->stripZipRootPrefix($name);
                if ($relative === '' || $this->shouldSkipBackendPath($relative)) {
                    continue;
                }
                if (str_contains($relative, '..')) {
                    continue;
                }

                $target = $root.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $dir = dirname($target);
                if (! is_dir($dir)) {
                    File::ensureDirectoryExists($dir);
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Cannot write '.$relative);
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    private function extractFrontendZip(string $zipPath, string $destPath): void
    {
        $dest = $destPath;
        if (! str_starts_with($dest, '/') && ! preg_match('/^[A-Za-z]:\\\\/', $dest)) {
            // Relative to base_path unless absolute.
            $dest = base_path($dest);
        }
        $dest = rtrim($dest, DIRECTORY_SEPARATOR.'/\\');
        File::ensureDirectoryExists($dest);

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open frontend zip.');
        }

        try {
            // If zip has a single top-level folder (dist/), strip it.
            $prefix = $this->detectSingleRootPrefix($zip);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }
                $name = str_replace('\\', '/', $name);
                if (str_ends_with($name, '/')) {
                    continue;
                }

                $relative = $name;
                if ($prefix !== '' && str_starts_with($relative, $prefix)) {
                    $relative = substr($relative, strlen($prefix));
                }
                $relative = ltrim($relative, '/');
                if ($relative === '' || str_contains($relative, '..')) {
                    continue;
                }

                $target = $dest.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                File::ensureDirectoryExists(dirname($target));

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Cannot write frontend file '.$relative);
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    private function writeVersionFiles(string $backendVersion, string $frontendVersion): void
    {
        $versionPath = base_path('VERSION');
        File::put($versionPath, $backendVersion."\n");

        // Persist for config('hub.*') on hosts that read .env overrides.
        // Do not rewrite full .env — only touch a small marker file.
        $marker = storage_path('app/private/code-update-version.json');
        File::ensureDirectoryExists(dirname($marker));
        File::put($marker, json_encode([
            'version' => $backendVersion,
            'frontend_version' => $frontendVersion,
            'applied_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }

    private function shouldSkipBackendPath(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        foreach (self::BACKEND_SKIP as $skip) {
            if ($relative === $skip || str_starts_with($relative, rtrim($skip, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * If archive was built from a folder (e.g. hub-finproms-backend/...), strip that root.
     */
    private function stripZipRootPrefix(string $name): string
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $parts = explode('/', $name);
        if (count($parts) > 1 && in_array($parts[0], [
            'hub-finproms-backend',
            'backend',
            'app-release',
        ], true)) {
            array_shift($parts);

            return implode('/', $parts);
        }

        // Heuristic: single shared root folder containing artisan or composer.json
        return $name;
    }

    private function detectSingleRootPrefix(ZipArchive $zip): string
    {
        $roots = [];
        for ($i = 0; $i < min($zip->numFiles, 50); $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $name = ltrim($name, '/');
            if ($name === '') {
                continue;
            }
            $root = explode('/', $name, 2)[0];
            $roots[$root] = true;
        }
        if (count($roots) === 1) {
            $only = array_key_first($roots);
            if (in_array($only, ['dist', 'dist-shared', 'dist-central', 'dist-myhub', 'build', 'frontend'], true)) {
                return $only.'/';
            }
        }

        return '';
    }
}
