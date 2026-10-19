<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Create / extract zip archives with ZipArchive when available, otherwise
 * fall back to the system zip/unzip binaries (common on cPanel when php-zip
 * is not enabled), then to a pure-PHP writer for create.
 */
class ZipSupport
{
    public static function zipExtensionLoaded(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function zipDirectory(string $sourceDir, string $zipPath): void
    {
        $sourceDir = realpath($sourceDir) ?: $sourceDir;
        if (! is_dir($sourceDir)) {
            throw new RuntimeException('Source directory for zip does not exist.');
        }

        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $parent = dirname($zipPath);
        if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
            throw new RuntimeException('Could not create zip destination directory.');
        }

        if (self::zipExtensionLoaded()) {
            $this->zipWithExtension($sourceDir, $zipPath);

            return;
        }

        if ($this->zipWithBinary($sourceDir, $zipPath)) {
            return;
        }

        $this->zipWithPhp($sourceDir, $zipPath);
    }

    public function extract(string $zipPath, string $destinationDir): void
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException('Zip archive not found.');
        }

        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0755, true) && ! is_dir($destinationDir)) {
            throw new RuntimeException('Could not create extract directory.');
        }

        $destinationDir = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $destinationDir), DIRECTORY_SEPARATOR);
        $destReal = realpath($destinationDir);
        if ($destReal === false) {
            throw new RuntimeException('Could not resolve extract directory.');
        }

        if (self::zipExtensionLoaded()) {
            $this->extractSafelyWithExtension($zipPath, $destReal);

            return;
        }

        if ($this->extractWithBinary($zipPath, $destReal)) {
            $this->assertExtractedTreeIsContained($destReal);

            return;
        }

        throw new RuntimeException(
            'PHP ZipArchive extension is not installed and the system unzip binary was not found. '
            .'Enable the php-zip extension (cPanel → Select PHP Version → Extensions → zip) '
            .'or install unzip on the server, then try again.'
        );
    }

    /**
     * Extract entry-by-entry, rejecting zip-slip (.. / absolute / escaped) paths.
     */
    private function extractSafelyWithExtension(string $zipPath, string $destReal): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open backup archive.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false) {
                    continue;
                }

                $relative = $this->safeZipRelativePath($name);
                if ($relative === null) {
                    continue;
                }
                if ($relative === '' || str_ends_with($name, '/')) {
                    if ($relative !== '') {
                        $dir = $destReal.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                            throw new RuntimeException('Could not create directory from archive: '.$relative);
                        }
                    }

                    continue;
                }

                $target = $destReal.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (! $this->pathIsInsideDirectory($target, $destReal)) {
                    throw new RuntimeException('Archive entry escapes extract directory: '.$name);
                }

                $parent = dirname($target);
                if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                    throw new RuntimeException('Could not create extract path for: '.$relative);
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    throw new RuntimeException('Cannot write extracted file: '.$relative);
                }
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Normalize a zip entry name; null means skip (unsafe / empty).
     */
    private function safeZipRelativePath(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:/#', $name)) {
            return null;
        }
        if (str_contains($name, "\0")) {
            return null;
        }

        $parts = [];
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    private function pathIsInsideDirectory(string $path, string $directory): bool
    {
        $directory = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $prefix = $directory.DIRECTORY_SEPARATOR;

        // Before the file exists, compare lexical normalized paths.
        $normDir = strtolower($directory);
        $normPath = strtolower($path);

        return $normPath === $normDir || str_starts_with($normPath, strtolower($prefix));
    }

    /**
     * After binary unzip, refuse trees that contain escaped paths (symlinks / ..).
     */
    private function assertExtractedTreeIsContained(string $destReal): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($destReal, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $real = $item->getRealPath();
            if ($real === false) {
                throw new RuntimeException('Archive extracted an unresolvable path (possible zip-slip).');
            }
            if (! $this->pathIsInsideDirectory($real, $destReal)) {
                throw new RuntimeException('Archive extracted a path outside the destination (zip-slip).');
            }
        }
    }

    private function zipWithExtension(string $sourceDir, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create zip archive.');
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }
            $relative = substr($path, strlen($sourceDir) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } else {
                $zip->addFile($path, $relative);
            }
        }

        $zip->close();

        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            throw new RuntimeException('Zip archive was not created.');
        }
    }

    private function zipWithBinary(string $sourceDir, string $zipPath): bool
    {
        $zipBin = $this->findBinary('zip');
        if (! $zipBin) {
            return false;
        }

        $absoluteZip = $zipPath;
        // zip writes relative paths from cwd; use absolute zip path.
        $cmd = sprintf(
            '%s -r -q %s .',
            escapeshellarg($zipBin),
            escapeshellarg($absoluteZip)
        );

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptor, $pipes, $sourceDir);
        if (! is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0 || ! is_file($zipPath) || filesize($zipPath) === 0) {
            @unlink($zipPath);

            return false;
        }

        return true;
    }

    private function extractWithBinary(string $zipPath, string $destinationDir): bool
    {
        $unzipBin = $this->findBinary('unzip');
        if (! $unzipBin) {
            return false;
        }

        $cmd = sprintf(
            '%s -o -q %s -d %s',
            escapeshellarg($unzipBin),
            escapeshellarg($zipPath),
            escapeshellarg($destinationDir)
        );

        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptor, $pipes);
        if (! is_resource($process)) {
            return false;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return $code === 0;
    }

    /**
     * Pure-PHP zip writer (deflate). Used when neither ZipArchive nor zip binary exist.
     */
    private function zipWithPhp(string $sourceDir, string $zipPath): void
    {
        $fh = fopen($zipPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Could not open zip file for writing.');
        }

        $cdrec = '';
        $entries = 0;

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $path = $file->getRealPath();
                if ($path === false) {
                    continue;
                }
                $relative = substr($path, strlen($sourceDir) + 1);
                $relative = str_replace('\\', '/', $relative);
                $data = file_get_contents($path);
                if ($data === false) {
                    throw new RuntimeException('Could not read file for backup zip: '.$relative);
                }
                $this->writePhpZipEntry($fh, $cdrec, $relative, $data);
                $entries++;
            }

            $beforeCd = ftell($fh);
            fwrite($fh, $cdrec);
            fwrite($fh, "\x50\x4b\x05\x06");
            fwrite($fh, pack('v', 0));
            fwrite($fh, pack('v', 0));
            fwrite($fh, pack('v', $entries));
            fwrite($fh, pack('v', $entries));
            fwrite($fh, pack('V', strlen($cdrec)));
            fwrite($fh, pack('V', $beforeCd));
            fwrite($fh, pack('v', 0));
        } finally {
            fclose($fh);
        }

        if (! is_file($zipPath) || filesize($zipPath) === 0) {
            throw new RuntimeException(
                'Could not create zip archive. Enable the php-zip extension on this server '
                .'(cPanel → Select PHP Version → Extensions → zip).'
            );
        }
    }

    /**
     * @param  resource  $fh
     */
    private function writePhpZipEntry($fh, string &$cdrec, string $filename, string $data): void
    {
        $uncsize = strlen($data);
        if ($uncsize < 256) {
            $zdata = $data;
            $cmethod = 0;
            $comsize = $uncsize;
        } else {
            $zdata = substr((string) gzcompress($data), 2, -4);
            $cmethod = 8;
            $comsize = strlen($zdata);
        }

        $crc = crc32($data);
        $date = getdate();
        $dostime = (
            (($date['year'] - 1980) << 25)
            | ($date['mon'] << 21)
            | ($date['mday'] << 16)
            | ($date['hours'] << 11)
            | ($date['minutes'] << 5)
            | ($date['seconds'] >> 1)
        );

        $offset = ftell($fh);
        $nameLen = strlen($filename);

        fwrite($fh, "\x50\x4b\x03\x04");
        fwrite($fh, pack('v', 20));
        fwrite($fh, pack('v', 0));
        fwrite($fh, pack('v', $cmethod));
        fwrite($fh, pack('V', $dostime));
        fwrite($fh, pack('V', $crc));
        fwrite($fh, pack('V', $comsize));
        fwrite($fh, pack('V', $uncsize));
        fwrite($fh, pack('v', $nameLen));
        fwrite($fh, pack('v', 0));
        fwrite($fh, $filename);
        fwrite($fh, $zdata);

        $cdrec .= "\x50\x4b\x01\x02";
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', 20);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', $cmethod);
        $cdrec .= pack('V', $dostime);
        $cdrec .= pack('V', $crc);
        $cdrec .= pack('V', $comsize);
        $cdrec .= pack('V', $uncsize);
        $cdrec .= pack('v', $nameLen);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('v', 0);
        $cdrec .= pack('V', 32);
        $cdrec .= pack('V', $offset);
        $cdrec .= $filename;
    }

    private function findBinary(string $name): ?string
    {
        foreach (['/usr/bin/', '/usr/local/bin/', 'C:\\Windows\\System32\\'] as $prefix) {
            $candidate = $prefix.$name.(str_contains($prefix, '\\') ? '.exe' : '');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $cmd = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where '.$name : 'which '.$name;
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        if ($code === 0 && isset($out[0]) && is_file($out[0])) {
            return $out[0];
        }

        return null;
    }
}
