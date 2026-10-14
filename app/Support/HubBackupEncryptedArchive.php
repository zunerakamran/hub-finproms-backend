<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * NFR-BACKUP-ENC — encrypt hub backup ZIP archives at rest (streaming AES-256-GCM).
 *
 * Format (binary):
 *   "HBENC1" (6) | version uint8=1 | salt 16 |
 *   repeated: chunk_len uint32-BE | iv 12 | tag 16 | ciphertext
 *   terminator: chunk_len = 0
 *
 * Use a shared HUB_BACKUP_ENCRYPTION_KEY on Central and content hubs so
 * uploaded backups can be decrypted for download / restore across servers.
 * Falls back to a key derived from APP_KEY when the dedicated key is unset
 * (single-server only — not suitable for Central↔hub dual-store).
 */
final class HubBackupEncryptedArchive
{
    public const MAGIC = 'HBENC1';

    public const VERSION = 1;

    private const CHUNK_SIZE = 1048576; // 1 MiB plaintext per chunk

    public static function enabled(): bool
    {
        if (! config('services.hub_backup.encrypt_at_rest', true)) {
            return false;
        }

        try {
            self::rawKey();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function isEncryptedFile(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }

        $magic = fread($fh, 6);
        fclose($fh);

        return $magic === self::MAGIC;
    }

    /**
     * Encrypt a file in place (atomic via temp sibling).
     */
    public static function encryptFileInPlace(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException('Cannot encrypt: backup file missing.');
        }

        if (self::isEncryptedFile($path)) {
            return;
        }

        $tmp = $path.'.encrypting.'.bin2hex(random_bytes(4));
        $in = fopen($path, 'rb');
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
            throw new RuntimeException('Cannot open backup file for encryption.');
        }

        try {
            $salt = random_bytes(16);
            $key = self::deriveKey($salt);

            fwrite($out, self::MAGIC);
            fwrite($out, chr(self::VERSION));
            fwrite($out, $salt);

            while (! feof($in)) {
                $plain = fread($in, self::CHUNK_SIZE);
                if ($plain === false || $plain === '') {
                    break;
                }

                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
                if ($cipher === false || strlen($tag) !== 16) {
                    throw new RuntimeException('Backup encryption failed (AES-GCM).');
                }

                fwrite($out, pack('N', strlen($cipher)));
                fwrite($out, $iv);
                fwrite($out, $tag);
                fwrite($out, $cipher);
            }

            fwrite($out, pack('N', 0));
        } finally {
            fclose($in);
            fclose($out);
        }

        if (! @rename($tmp, $path)) {
            if (! @copy($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Could not replace backup with encrypted archive.');
            }
            @unlink($tmp);
        }
    }

    /**
     * Decrypt to a temporary file. Caller MUST unlink the returned path.
     */
    public static function decryptToTemp(string $path): string
    {
        if (! self::isEncryptedFile($path)) {
            throw new RuntimeException('Backup is not an encrypted archive.');
        }

        $tmp = storage_path('app/backups/tmp/decrypt_'.bin2hex(random_bytes(8)).'.zip');
        $dir = dirname($tmp);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create temp directory for backup decrypt.');
        }

        $in = fopen($path, 'rb');
        $out = fopen($tmp, 'wb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($tmp);
            throw new RuntimeException('Cannot open encrypted backup for decrypt.');
        }

        try {
            $magic = fread($in, 6);
            if ($magic !== self::MAGIC) {
                throw new RuntimeException('Invalid encrypted backup header.');
            }

            $version = ord((string) fread($in, 1));
            if ($version !== self::VERSION) {
                throw new RuntimeException('Unsupported backup encryption version: '.$version);
            }

            $salt = fread($in, 16);
            if ($salt === false || strlen($salt) !== 16) {
                throw new RuntimeException('Corrupt encrypted backup (salt).');
            }

            $key = self::deriveKey($salt);

            while (true) {
                $lenRaw = fread($in, 4);
                if ($lenRaw === false || strlen($lenRaw) !== 4) {
                    throw new RuntimeException('Corrupt encrypted backup (chunk length).');
                }
                $len = unpack('Nlen', $lenRaw)['len'];
                if ($len === 0) {
                    break;
                }
                if ($len > self::CHUNK_SIZE + 128) {
                    throw new RuntimeException('Corrupt encrypted backup (chunk too large).');
                }

                $iv = fread($in, 12);
                $tag = fread($in, 16);
                $cipher = fread($in, $len);
                if ($iv === false || $tag === false || $cipher === false
                    || strlen($iv) !== 12 || strlen($tag) !== 16 || strlen($cipher) !== $len) {
                    throw new RuntimeException('Corrupt encrypted backup (chunk body).');
                }

                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($plain === false) {
                    throw new RuntimeException(
                        'Backup decrypt failed. Check HUB_BACKUP_ENCRYPTION_KEY matches the key used to encrypt.'
                    );
                }

                fwrite($out, $plain);
            }
        } catch (Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($tmp);
            throw $e;
        }

        fclose($in);
        fclose($out);

        return $tmp;
    }

    /**
     * Return [plaintext zip path, optional cleanup callable].
     * Cleanup unlinks a temp decrypt file when the archive was encrypted.
     *
     * @return array{0: string, 1: (callable(): void)|null}
     */
    public static function resolvePlaintext(string $path): array
    {
        if (! self::isEncryptedFile($path)) {
            return [$path, null];
        }

        $tmp = self::decryptToTemp($path);

        return [
            $tmp,
            static function () use ($tmp): void {
                @unlink($tmp);
            },
        ];
    }

    private static function deriveKey(string $salt): string
    {
        return hash_hkdf('sha256', self::rawKey(), 32, 'hub-backup-v1', $salt);
    }

    private static function rawKey(): string
    {
        $configured = (string) config('services.hub_backup.encryption_key', '');
        if ($configured !== '') {
            if (str_starts_with($configured, 'base64:')) {
                $decoded = base64_decode(substr($configured, 7), true);
                if ($decoded !== false && strlen($decoded) >= 32) {
                    return substr($decoded, 0, 32);
                }
            }
            if (strlen($configured) >= 32) {
                return hash('sha256', $configured, true);
            }

            return hash('sha256', $configured, true);
        }

        $appKey = (string) config('app.key', '');
        if ($appKey === '') {
            throw new RuntimeException(
                'Set HUB_BACKUP_ENCRYPTION_KEY (preferred) or APP_KEY to encrypt hub backups.'
            );
        }

        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false && $decoded !== '') {
                return hash('sha256', 'hub-backup|'.$decoded, true);
            }
        }

        return hash('sha256', 'hub-backup|'.$appKey, true);
    }
}
