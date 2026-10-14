<?php

namespace Tests\Unit;

use App\Support\HubBackupEncryptedArchive;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HubBackupEncryptedArchiveTest extends TestCase
{
    public function test_encrypt_and_decrypt_round_trip(): void
    {
        config([
            'services.hub_backup.encrypt_at_rest' => true,
            'services.hub_backup.encryption_key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        $dir = storage_path('app/backups/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/unit_backup_'.uniqid('', true).'.zip';
        $payload = random_bytes(200000); // > chunk edge
        file_put_contents($path, $payload);

        HubBackupEncryptedArchive::encryptFileInPlace($path);
        $this->assertTrue(HubBackupEncryptedArchive::isEncryptedFile($path));
        $this->assertNotSame($payload, file_get_contents($path));

        [$plain, $cleanup] = HubBackupEncryptedArchive::resolvePlaintext($path);
        $this->assertSame($payload, file_get_contents($plain));
        if (is_callable($cleanup)) {
            $cleanup();
        }
        @unlink($path);
    }

    public function test_legacy_plaintext_passes_through(): void
    {
        $dir = storage_path('app/backups/tmp');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/unit_plain_'.uniqid('', true).'.zip';
        file_put_contents($path, "PK\x03\x04plain-zip-bytes");

        $this->assertFalse(HubBackupEncryptedArchive::isEncryptedFile($path));
        [$plain, $cleanup] = HubBackupEncryptedArchive::resolvePlaintext($path);
        $this->assertSame($path, $plain);
        $this->assertNull($cleanup);
        @unlink($path);
    }
}
