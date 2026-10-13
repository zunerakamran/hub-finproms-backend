<?php

namespace App\Support;

use App\Casts\SafeEncrypted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Encrypt Generic Compliance attachment / supporting-file bytes at rest (APP_KEY).
 *
 * Uses the gc_encrypted disk (storage/app/gc-encrypted) — not Laravel 11's
 * storage/app/private "local" disk, which is often missing on cPanel.
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
        if (! filled(config('app.key'))) {
            throw new RuntimeException(
                'No application encryption key has been specified. Set APP_KEY in .env.'
            );
        }

        $plain = file_get_contents($file->getRealPath());
        if ($plain === false) {
            throw new RuntimeException('Unable to read uploaded Generic Compliance file.');
        }

        $disk = Storage::disk(self::DISK_ENCRYPTED);
        $disk->makeDirectory(self::DIR);

        $path = self::DIR.'/'.Str::uuid()->toString().'.enc';
        $payload = self::MAGIC.Crypt::encryptString($plain);
        $disk->put($path, $payload);

        if (! $disk->exists($path)) {
            throw new RuntimeException(
                'Unable to write encrypted Generic Compliance file to gc_encrypted disk.'
            );
        }

        return [
            'file_path' => $path,
            'file_url' => null,
            'size_bytes' => strlen($plain),
            'is_encrypted' => true,
        ];
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
                .'Re-upload the file after deploying the latest API, or run: php artisan security:encrypt-general-compliance'
            );
        }

        if ($raw === '') {
            throw new RuntimeException('Generic Compliance file is empty.');
        }

        return self::decryptPayload($raw);
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

        // Flysystem disks first (works with Storage::fake in tests + real disks).
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

        // Absolute paths for shared-host quirks / early private-disk uploads.
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
                if (Storage::disk(self::DISK_ENCRYPTED)->exists($path)) {
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

        $disk = Storage::disk(self::DISK_ENCRYPTED);
        $disk->makeDirectory(self::DIR);
        $newPath = self::DIR.'/'.Str::uuid()->toString().'.enc';
        $payload = self::MAGIC.Crypt::encryptString($plain);
        $disk->put($newPath, $payload);

        try {
            if (Storage::disk(self::DISK_PUBLIC)->exists($path)) {
                Storage::disk(self::DISK_PUBLIC)->delete($path);
            }
        } catch (Throwable) {
            // ignore
        }

        return [
            'file_path' => $newPath,
            'file_url' => null,
            'is_encrypted' => true,
        ];
    }
}
