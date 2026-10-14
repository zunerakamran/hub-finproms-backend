<?php

namespace App\Services;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

/**
 * NFR-SEC-FW — reject dangerous uploads and optionally scan with ClamAV.
 */
class UploadSecurityService
{
    public function __construct(
        private HubService $hubs,
        private SecurityMonitoringService $securityMonitoring,
    ) {}

    /**
     * Validate every uploaded file on the request. Throws ValidationException on reject.
     */
    public function assertRequestUploadsSafe(Request $request): void
    {
        if (! config('security_firewall.enabled', true)
            || ! config('security_firewall.upload_scan_enabled', true)) {
            return;
        }

        foreach ($this->collectUploads($request) as [$field, $file]) {
            $this->assertFileSafe($file, $field, $request);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertFileSafe(UploadedFile $file, string $field = 'file', ?Request $request = null): void
    {
        if (! config('security_firewall.enabled', true)
            || ! config('security_firewall.upload_scan_enabled', true)) {
            return;
        }

        $name = (string) $file->getClientOriginalName();
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if ($extension === '' && str_contains($name, '.')) {
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        }

        $blocked = array_map('strtolower', (array) config('security_firewall.blocked_extensions', []));
        if ($extension !== '' && in_array($extension, $blocked, true)) {
            $this->reject($request, $field, $name, 'data.upload_rejected', SecurityEvent::SEVERITY_MEDIUM, sprintf(
                'Upload rejected: disallowed file type ".%s" (%s).',
                $extension,
                $name
            ));
        }

        if (config('security_firewall.block_svg', true)
            && ($extension === 'svg' || str_contains(strtolower((string) $file->getClientMimeType()), 'svg'))) {
            $this->reject($request, $field, $name, 'data.upload_rejected', SecurityEvent::SEVERITY_MEDIUM, sprintf(
                'Upload rejected: SVG uploads are not allowed (%s).',
                $name
            ));
        }

        // Embedded dangerous extensions (e.g. invoice.php.jpg / shell.phtml.pdf).
        $lowerName = strtolower($name);
        foreach ($blocked as $danger) {
            if ($danger === '') {
                continue;
            }
            if (str_contains($lowerName, '.'.$danger.'.')) {
                $this->reject($request, $field, $name, 'data.upload_rejected', SecurityEvent::SEVERITY_HIGH, sprintf(
                    'Upload rejected: filename embeds dangerous extension ".%s" (%s).',
                    $danger,
                    $name
                ));
            }
        }

        if (config('security_firewall.malware_scan_enabled', false)) {
            $this->scanForMalware($file, $field, $name, $request);
        }
    }

    /**
     * @return list<array{0: string, 1: UploadedFile}>
     */
    private function collectUploads(Request $request): array
    {
        $out = [];
        foreach ($request->allFiles() as $field => $fileOrFiles) {
            $files = is_array($fileOrFiles) ? $fileOrFiles : [$fileOrFiles];
            foreach ($files as $key => $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $label = is_int($key) ? $field.'.'.$key : (string) $field;
                    $out[] = [$label, $file];
                } elseif (is_array($file)) {
                    foreach ($file as $nested) {
                        if ($nested instanceof UploadedFile && $nested->isValid()) {
                            $out[] = [(string) $field, $nested];
                        }
                    }
                }
            }
        }

        return $out;
    }

    private function scanForMalware(UploadedFile $file, string $field, string $name, ?Request $request): void
    {
        $binary = (string) config('security_firewall.malware_scan_binary', 'clamscan');
        $timeout = max(5, (int) config('security_firewall.malware_scan_timeout_seconds', 30));
        $required = (bool) config('security_firewall.malware_scan_required', false);
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '' || ! is_readable($path)) {
            if ($required) {
                $this->reject($request, $field, $name, 'data.malware_scan_failed', SecurityEvent::SEVERITY_HIGH,
                    'Malware scan could not read the uploaded file.');
            }

            return;
        }

        try {
            $result = Process::timeout($timeout)->run([
                $binary,
                '--no-summary',
                '--infected',
                $path,
            ]);
        } catch (\Throwable $e) {
            Log::warning('NFR-SEC-FW malware scan failed to start', [
                'binary' => $binary,
                'error' => $e->getMessage(),
            ]);
            if ($required) {
                $this->reject($request, $field, $name, 'data.malware_scan_failed', SecurityEvent::SEVERITY_HIGH,
                    'Malware scanner is unavailable; upload refused.');
            }

            return;
        }

        // clamscan: 0 = clean, 1 = infected, 2 = error
        $exit = $result->exitCode();
        if ($exit === 1) {
            $this->reject($request, $field, $name, 'data.malware_detected', SecurityEvent::SEVERITY_CRITICAL, sprintf(
                'Malware detected in upload "%s". File was rejected.',
                $name
            ));
        }

        if ($exit === 2 || $exit === null || $exit < 0) {
            Log::warning('NFR-SEC-FW malware scan error', [
                'exit' => $exit,
                'stderr' => mb_substr($result->errorOutput(), 0, 500),
            ]);
            if ($required) {
                $this->reject($request, $field, $name, 'data.malware_scan_failed', SecurityEvent::SEVERITY_HIGH,
                    'Malware scanner reported an error; upload refused.');
            }
        }
    }

    private function reject(
        ?Request $request,
        string $field,
        string $name,
        string $eventType,
        string $severity,
        string $description,
    ): void {
        $this->recordEvent($request, $eventType, $severity, $description, [
            'field' => $field,
            'filename' => mb_substr($name, 0, 255),
        ]);

        throw ValidationException::withMessages([
            $field => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordEvent(
        ?Request $request,
        string $eventType,
        string $severity,
        string $description,
        array $properties = [],
    ): void {
        if (! config('security_firewall.record_security_events', true)) {
            return;
        }

        try {
            $hub = $this->hubs->current();
            if (! $hub || ! $hub->hasSecurityMonitoringFunctionality()) {
                return;
            }

            $title = match ($eventType) {
                'data.malware_detected' => 'Malware detected in upload',
                'data.malware_scan_failed' => 'Malware scan failed',
                default => 'Suspicious or disallowed upload rejected',
            };

            $this->securityMonitoring->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_DATA,
                'event_type' => $eventType,
                'severity' => $severity,
                'title' => $title,
                'description' => $description,
                'actor' => $request?->user(),
                'request' => $request,
                'properties' => $properties,
                'create_incident' => $eventType === 'data.malware_detected',
            ]);
        } catch (\Throwable) {
            // Never break the rejection path for monitoring failures.
        }
    }
}
