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
 * New uploads go to the private "local" disk with a GCENC1 payload.
 * Legacy public-disk plaintext files remain readable until backfill.
 */
class GeneralComplianceEncryptedFiles
{
    public const MAGIC = 'GCENC1:';

    public const DISK_ENCRYPTED = 'local';

    public const DISK_LEGACY = 'public';

    public const DIR = 'general-compliance/enc';

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
        if (! $disk->exists(self::DIR)) {
            $disk->makeDirectory(self::DIR);
        }

        $path = self::DIR.'/'.Str::uuid()->toString().'.enc';
        $payload = self::MAGIC.Crypt::encryptString($plain);
        if (! $disk->put($path, $payload)) {
            throw new RuntimeException('Unable to write encrypted Generic Compliance file to private storage.');
        }

        return [
            'file_path' => $path,
            'file_url' => null,
            'size_bytes' => strlen($plain),
            'is_encrypted' => true,
        ];
    }

    /**
     * Read plaintext bytes for download (decrypts when marked encrypted / magic present).
     */
    public static function readPlaintext(string $path, bool $isEncrypted): string
    {
        $disk = $isEncrypted || str_starts_with($path, self::DIR.'/')
            ? self::DISK_ENCRYPTED
            : self::DISK_LEGACY;

        if (! Storage::disk($disk)->exists($path)) {
            // Legacy rows may still point at public after a partial migrate.
            if ($disk === self::DISK_ENCRYPTED && Storage::disk(self::DISK_LEGACY)->exists($path)) {
                $disk = self::DISK_LEGACY;
            } else {
                throw new RuntimeException('Generic Compliance file is missing from storage.');
            }
        }

        $raw = Storage::disk($disk)->get($path);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException('Generic Compliance file is empty or unreadable.');
        }

        return self::decryptPayload($raw);
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

        // Legacy Laravel ciphertext without magic (if any) or plaintext.
        if (SafeEncrypted::looksLikeLaravelCiphertext($raw)) {
            try {
                return Crypt::decryptString($raw);
            } catch (Throwable) {
                // Fall through — treat as plaintext if decrypt fails oddly.
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
     * Move a legacy public plaintext file onto the encrypted private disk.
     *
     * @return array{file_path: string, file_url: null, is_encrypted: bool}|null  null if already encrypted / missing
     */
    public static function encryptExistingPublicFile(string $path): ?array
    {
        if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return null;
        }

        if (str_starts_with($path, self::DIR.'/')) {
            if (Storage::disk(self::DISK_ENCRYPTED)->exists($path)) {
                $raw = (string) Storage::disk(self::DISK_ENCRYPTED)->get($path);
                if (self::looksEncryptedOnDisk($raw)) {
                    return null; // already done
                }
            }
        }

        $sourceDisk = Storage::disk(self::DISK_LEGACY)->exists($path)
            ? self::DISK_LEGACY
            : (Storage::disk(self::DISK_ENCRYPTED)->exists($path) ? self::DISK_ENCRYPTED : null);

        if ($sourceDisk === null) {
            return null;
        }

        $raw = (string) Storage::disk($sourceDisk)->get($path);
        if (self::looksEncryptedOnDisk($raw)) {
            // Already ciphertext but maybe wrong disk/path bookkeeping.
            if ($sourceDisk === self::DISK_ENCRYPTED && str_starts_with($path, self::DIR.'/')) {
                return null;
            }
            $plain = self::decryptPayload($raw);
        } else {
            $plain = $raw;
        }

        if (! filled(config('app.key'))) {
            throw new RuntimeException('No application encryption key has been specified. Set APP_KEY in .env.');
        }

        $newPath = self::DIR.'/'.Str::uuid()->toString().'.enc';
        $payload = self::MAGIC.Crypt::encryptString($plain);
        Storage::disk(self::DISK_ENCRYPTED)->put($newPath, $payload);

        if ($sourceDisk === self::DISK_LEGACY && $path !== $newPath) {
            Storage::disk(self::DISK_LEGACY)->delete($path);
        } elseif ($sourceDisk === self::DISK_ENCRYPTED && $path !== $newPath) {
            Storage::disk(self::DISK_ENCRYPTED)->delete($path);
        }

        return [
            'file_path' => $newPath,
            'file_url' => null,
            'is_encrypted' => true,
        ];
    }
}
