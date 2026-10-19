<?php

namespace Tests\Unit;

use App\Support\ZipSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class ZipSupportExtractTest extends TestCase
{
    #[Test]
    public function it_extracts_normal_entries_into_destination(): void
    {
        if (! ZipSupport::zipExtensionLoaded()) {
            $this->markTestSkipped('php-zip extension required');
        }

        $zipPath = storage_path('framework/testing/zip-safe-'.uniqid('', true).'.zip');
        $dest = storage_path('framework/testing/zip-out-'.uniqid('', true));
        @mkdir(dirname($zipPath), 0755, true);
        @mkdir($dest, 0755, true);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('files/public/hello.txt', 'ok');
        $zip->close();

        try {
            (new ZipSupport)->extract($zipPath, $dest);
            $this->assertFileExists($dest.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'hello.txt');
            $this->assertSame('ok', file_get_contents(
                $dest.DIRECTORY_SEPARATOR.'files'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'hello.txt'
            ));
        } finally {
            @unlink($zipPath);
            $this->removeDir($dest);
        }
    }

    #[Test]
    public function it_rejects_zip_slip_dotdot_entries(): void
    {
        if (! ZipSupport::zipExtensionLoaded()) {
            $this->markTestSkipped('php-zip extension required');
        }

        $zipPath = storage_path('framework/testing/zip-slip-'.uniqid('', true).'.zip');
        $dest = storage_path('framework/testing/zip-slip-out-'.uniqid('', true));
        $outside = storage_path('framework/testing/zip-slip-outside-'.uniqid('', true).'.txt');
        @mkdir(dirname($zipPath), 0755, true);
        @mkdir($dest, 0755, true);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        // Classic zip-slip relative escape.
        $zip->addFromString('../'.basename($outside), 'pwned');
        $zip->close();

        try {
            (new ZipSupport)->extract($zipPath, $dest);
            $this->assertFileDoesNotExist($outside);
            $this->assertFileDoesNotExist($dest.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.basename($outside));
        } finally {
            @unlink($zipPath);
            @unlink($outside);
            $this->removeDir($dest);
        }
    }

    private function removeDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($path);
    }
}
