<?php

namespace App\Services\WebsiteCompliance;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TemplatePreviewCaptureService
{
    /**
     * Capture a full-page screenshot of the template preview URL.
     * Returns a relative path like /website-compliance/uploaded-images/{filename} or null on failure.
     */
    public function capture(string $previewUrl): ?string
    {
        $previewUrl = trim($previewUrl);
        if ($previewUrl === '' || ! filter_var($previewUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $filename = time().'_'.Str::random(10).'_preview.jpg';
        $outputPath = storage_path('app/uploads/'.$filename);

        if (! is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0755, true);
        }

        $scriptPath = base_path('scripts/website-compliance/capture-template-preview.mjs');
        if (! is_file($scriptPath)) {
            Log::warning('Template preview capture script not found', ['path' => $scriptPath]);

            return null;
        }

        $nodeBinary = $this->resolveNodeBinary();
        if (! $nodeBinary) {
            Log::warning('Node.js not found — cannot capture template preview screenshot');

            return null;
        }

        $scriptsDir = base_path('scripts/website-compliance');
        $command = sprintf(
            'cd %s && %s %s %s %s 2>&1',
            escapeshellarg($scriptsDir),
            escapeshellarg($nodeBinary),
            escapeshellarg($scriptPath),
            escapeshellarg($previewUrl),
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ! is_file($outputPath)) {
            Log::warning('Template preview capture failed', [
                'url' => $previewUrl,
                'exit_code' => $exitCode,
                'output' => implode("\n", $output),
            ]);

            return null;
        }

        return '/website-compliance/uploaded-images/'.$filename;
    }

    private function resolveNodeBinary(): ?string
    {
        $candidates = ['node', 'nodejs'];
        if ($configured = config('services.website_compliance.template_preview_node_binary')) {
            array_unshift($candidates, $configured);
        }

        foreach ($candidates as $binary) {
            $check = escapeshellarg($binary).' --version 2>&1';
            $version = shell_exec($check);
            if (is_string($version) && trim($version) !== '') {
                return $binary;
            }
        }

        return null;
    }
}
