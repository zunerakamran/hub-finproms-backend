<?php

namespace App\Support;

use App\Casts\SafeEncrypted;
use App\Models\Hub;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Encrypt Generic Compliance attachment / supporting-file bytes at rest (APP_KEY).
 *
 * Uses the gc_encrypted disk (storage/app/gc-encrypted) — not Laravel 11's
 * storage/app/private "local" disk, which is often missing on cPanel.
 *
 * When Central acts on a remote content hub, DB rows live on the remote DB but
 * encrypted bytes live on that hub's disk — use storeOnHub / fetchFromHub.
 */
class GeneralComplianceEncryptedFiles
{
    public const MAGIC = 'GCENC1:';

    public const DISK_ENCRYPTED = 'gc_encrypted';

    public const DISK_PRIVATE_LEGACY = 'local';

    public const DISK_PUBLIC = 'public';

    /** Relative path prefix on gc_encrypted disk. */
    public const DIR = 'enc';

    /**
     * @return array{file_path: string, file_url: null, size_bytes: int, is_encrypted: bool}
     */
    public static function storeUpload(UploadedFile $file): array
    {
        // Central acting remotely: write bytes onto the content hub that owns the DB.
        if (WcDatabaseContext::active() && WcDatabaseContext::hubId()) {
            $hub = Hub::query()->find((int) WcDatabaseContext::hubId());
            if ($hub && $hub->isContentHub() && filled($hub->apiBaseUrl())) {
                return self::storeOnHub($hub, $file);
            }
        }

        return self::storeUploadLocal($file);
    }

    /**
     * @return array{file_path: string, file_url: null, size_bytes: int, is_encrypted: bool}
     */
    public static function storeUploadLocal(UploadedFile $file): array
    {
        if (! filled(config('app.key'))) {
            throw new RuntimeException(
                'No application encryption key has been specified. Set APP_KEY in .env.'
            );
        }

        $plain = file_get_contents($file->getRealPath());
        if ($plain === false) {
            throw new RuntimeException('Unable to read uploaded Generic Compliance file.');
        }

        $path = self::DIR.'/'.Str::uuid()->toString().'.enc';
        $payload = self::MAGIC.Crypt::encryptString($plain);
        self::writeEncryptedBytes($path, $payload);

        return [
            'file_path' => $path,
            'file_url' => null,
            'size_bytes' => strlen($plain),
            'is_encrypted' => true,
        ];
    }

    /**
     * Store an encrypted upload on a remote content hub (Central → hub).
     *
     * @return array{file_path: string, file_url: null, size_bytes: int, is_encrypted: bool}
     */
    public static function storeOnHub(Hub $hub, UploadedFile $file): array
    {
        $api = $hub->apiBaseUrl();
        if (! filled($api)) {
            throw new RuntimeException(
                'Hub “'.$hub->slug.'” has no api_url; cannot store encrypted GC files remotely.'
            );
        }

        $token = self::hubAuthToken($hub);
        if ($token === '') {
            throw new RuntimeException(
                'Hub “'.$hub->slug.'” has no backup_token / HUB_BACKUP_SECRET; '
                .'cannot store encrypted GC files remotely.'
            );
        }

        $absolute = $file->getRealPath();
        if ($absolute === false || ! is_readable($absolute)) {
            throw new RuntimeException('Unable to read uploaded Generic Compliance file.');
        }

        $verify = (bool) config('services.http_tls_verify', true);
        $response = Http::withOptions(['verify' => $verify])
            ->timeout(120)
            ->withHeaders([
                'X-Hub-Backup-Key' => $token,
                'X-Hub-Slug' => (string) $hub->slug,
                'Accept' => 'application/json',
            ])
            ->attach('file', file_get_contents($absolute), $file->getClientOriginalName() ?: 'upload.bin')
            ->post(rtrim($api, '/').'/internal/gc/file');

        if (! $response->successful()) {
            $message = $response->json('message') ?: ('HTTP '.$response->status());
            throw new RuntimeException(
                'Remote hub rejected GC file upload ('.$hub->slug.'): '.$message
            );
        }

        $data = $response->json();
        $path = is_array($data) ? (string) ($data['file_path'] ?? '') : '';
        if ($path === '') {
            throw new RuntimeException('Remote hub did not return a GC file_path.');
        }

        return [
            'file_path' => $path,
            'file_url' => null,
            'size_bytes' => (int) (is_array($data) ? ($data['size_bytes'] ?? $file->getSize() ?: 0) : 0),
            'is_encrypted' => true,
        ];
    }

    /**
     * Fetch decrypted bytes for an attachment from a remote content hub.
     */
    public static function fetchFromHub(Hub $hub, int $attachmentId): string
    {
        $api = $hub->apiBaseUrl();
        if (! filled($api)) {
            throw new RuntimeException(
                'Hub “'.$hub->slug.'” has no api_url; cannot download encrypted GC files from Central.'
            );
        }

        $token = self::hubAuthToken($hub);
        if ($token === '') {
            throw new RuntimeException(
                'Hub “'.$hub->slug.'” has no backup_token / HUB_BACKUP_SECRET; '
                .'cannot download encrypted GC files from Central.'
            );
        }

        $verify = (bool) config('services.http_tls_verify', true);
        $response = Http::withOptions(['verify' => $verify])
            ->timeout(120)
            ->withHeaders([
                'X-Hub-Backup-Key' => $token,
                'X-Hub-Slug' => (string) $hub->slug,
                'Accept' => '*/*',
            ])
            ->get(rtrim($api, '/').'/internal/gc/file/'.$attachmentId);

        if (! $response->successful()) {
            $message = $response->json('message');
            if (! is_string($message) || $message === '') {
                $message = trim($response->body());
            }
            if ($message === '') {
                $message = 'HTTP '.$response->status();
            }
            throw new RuntimeException(
                'Remote hub could not serve GC file #'.$attachmentId.' ('.$hub->slug.'): '.$message
            );
        }

        $body = $response->body();
        if ($body === '') {
            throw new RuntimeException('Remote hub returned an empty GC file.');
        }

        return $body;
    }

    /**
     * Read plaintext locally, or from the acting remote hub when the file is not on this disk.
     */
    public static function readPlaintextOrRemote(string $path, bool $isEncrypted, ?int $attachmentId = null): string
    {
        try {
            return self::readPlaintext($path, $isEncrypted);
        } catch (Throwable $localError) {
            if (! $attachmentId
                || ! WcDatabaseContext::active()
                || ! WcDatabaseContext::hubId()
            ) {
                throw $localError;
            }

            $hub = Hub::query()->find((int) WcDatabaseContext::hubId());
            if (! $hub || ! $hub->isContentHub() || ! filled($hub->apiBaseUrl())) {
                throw $localError;
            }

            try {
                return self::fetchFromHub($hub, $attachmentId);
            } catch (Throwable $remoteError) {
                throw new RuntimeException(
                    $localError->getMessage()
                    .' Also failed fetching from hub “'.$hub->slug.'”: '.$remoteError->getMessage(),
                    0,
                    $remoteError
                );
            }
        }
    }

    /**
     * Read plaintext bytes (decrypts GCENC1 / Laravel ciphertext when present).
     */
    public static function readPlaintext(string $path, bool $isEncrypted = false): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            throw new RuntimeException('Generic Compliance file path is empty.');
        }

        $raw = self::readRawBytes($path);
        if ($raw === null) {
            throw new RuntimeException(
                'Generic Compliance file is missing from storage (path: '.$path.'). '
                .'If you are on Central viewing a content hub, deploy the latest API on that hub '
                .'and ensure backup_token / HUB_BACKUP_SECRET match. Otherwise re-upload the file.'
            );
        }

        if ($raw === '') {
            throw new RuntimeException('Generic Compliance file is empty.');
        }

        return self::decryptPayload($raw);
    }

    private static function writeEncryptedBytes(string $path, string $payload): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        // Prefer configured disk first (honours Storage::fake in tests).
        try {
            $disk = Storage::disk(self::DISK_ENCRYPTED);
            $disk->makeDirectory(self::DIR);
            $disk->put($path, $payload);
            if ($disk->exists($path)) {
                return;
            }
        } catch (Throwable) {
            // fall through to absolute write for shared-host quirks
        }

        $absoluteDir = storage_path('app/gc-encrypted/'.dirname($path));
        if (! is_dir($absoluteDir) && ! mkdir($absoluteDir, 0755, true) && ! is_dir($absoluteDir)) {
            throw new RuntimeException(
                'Unable to create gc-encrypted directory (check storage permissions).'
            );
        }

        $absolute = storage_path('app/gc-encrypted/'.$path);
        $written = @file_put_contents($absolute, $payload);
        if ($written === false || ! is_file($absolute)) {
            throw new RuntimeException(
                'Unable to write encrypted Generic Compliance file to gc_encrypted disk.'
            );
        }
    }

    private static function hubAuthToken(Hub $hub): string
    {
        $token = (string) ($hub->backup_token ?: '');
        if ($token !== '') {
            return $token;
        }

        return (string) config('services.hub_backup.secret', '');
    }

    private static function readRawBytes(string $path): ?string
    {
        $pathTries = array_values(array_unique(array_filter([
            $path,
            self::DIR.'/'.basename($path),
            'general-compliance/'.$path,
            'general-compliance/enc/'.basename($path),
            str_starts_with($path, 'general-compliance/') ? $path : null,
        ])));

        // Absolute paths first (shared-host / cPanel quirks).
        $absoluteTries = [];
        foreach ($pathTries as $try) {
            $absoluteTries[] = storage_path('app/gc-encrypted/'.$try);
            $absoluteTries[] = storage_path('app/private/'.$try);
            $absoluteTries[] = storage_path('app/public/'.$try);
            $absoluteTries[] = storage_path('app/'.$try);
        }

        foreach (array_unique($absoluteTries) as $absolute) {
            if (is_file($absolute) && is_readable($absolute)) {
                $contents = file_get_contents($absolute);

                return $contents === false ? null : $contents;
            }
        }

        // Flysystem disks (Storage::fake in tests + real disks).
        foreach ([self::DISK_ENCRYPTED, self::DISK_PRIVATE_LEGACY, self::DISK_PUBLIC] as $diskName) {
            try {
                $disk = Storage::disk($diskName);
            } catch (Throwable) {
                continue;
            }
            foreach ($pathTries as $try) {
                try {
                    if ($disk->exists($try)) {
                        $contents = $disk->get($try);
                        if (is_string($contents)) {
                            return $contents;
                        }
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    public static function decryptPayload(string $raw): string
    {
        if (str_starts_with($raw, self::MAGIC)) {
            try {
                return Crypt::decryptString(substr($raw, strlen(self::MAGIC)));
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Unable to decrypt Generic Compliance file (APP_KEY mismatch?).',
                    0,
                    $e
                );
            }
        }

        if (SafeEncrypted::looksLikeLaravelCiphertext($raw)) {
            try {
                return Crypt::decryptString($raw);
            } catch (Throwable) {
                // Fall through — treat as plaintext.
            }
        }

        return $raw;
    }

    public static function looksEncryptedOnDisk(string $raw): bool
    {
        if (str_starts_with($raw, self::MAGIC)) {
            return true;
        }

        return SafeEncrypted::looksLikeLaravelCiphertext($raw);
    }

    /**
     * @return array{file_path: string, file_url: null, is_encrypted: bool}|null
     */
    public static function encryptExistingPublicFile(string $path): ?array
    {
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $raw = self::readRawBytes($path);
        if ($raw === null) {
            return null;
        }

        if (self::looksEncryptedOnDisk($raw) && str_starts_with($path, self::DIR.'/')) {
            try {
                if (Storage::disk(self::DISK_ENCRYPTED)->exists($path)
                    || is_file(storage_path('app/gc-encrypted/'.$path))
                ) {
                    return null;
                }
            } catch (Throwable) {
                // continue re-encrypt into new disk
            }
        }

        $plain = self::looksEncryptedOnDisk($raw) ? self::decryptPayload($raw) : $raw;

        if (! filled(config('app.key'))) {
            throw new RuntimeException('No application encryption key has been specified. Set APP_KEY in .env.');
        }

        $newPath = self::DIR.'/'.Str::uuid()->toString().'.enc';
        $payload = self::MAGIC.Crypt::encryptString($plain);
        self::writeEncryptedBytes($newPath, $payload);

        try {
            if (Storage::disk(self::DISK_PUBLIC)->exists($path)) {
                Storage::disk(self::DISK_PUBLIC)->delete($path);
            }
        } catch (Throwable) {
            // ignore
        }

        $publicAbsolute = storage_path('app/public/'.$path);
        if (is_file($publicAbsolute)) {
            @unlink($publicAbsolute);
        }

        return [
            'file_path' => $newPath,
            'file_url' => null,
            'is_encrypted' => true,
        ];
    }
}
