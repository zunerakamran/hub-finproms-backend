<?php

namespace App\Services\WebsiteCompliance;

use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CpanelSyncService
{
    /**
     * Push deployment config (DB creds, URLs, advisor id) to the advisor cPanel api.php.
     */
    public static function pushDeployConfig(TemplateRequest $templateRequest, mixed $advisorId = null): bool
    {
        if (! $templateRequest->cpanel_domain) {
            Log::info('pushDeployConfig: no cpanel_domain on template request #'.$templateRequest->id);

            return false;
        }

        $advisorId = $advisorId ?? $templateRequest->advisor_id ?? $templateRequest->assigned_advisor_id;

        $payload = self::buildBasePayload($templateRequest, $advisorId);
        $payload['write_config'] = true;
        $payload['sections'] = $advisorId
            ? self::advisorSectionPayloadForTemplateRequest((int) $templateRequest->id)
            : [];

        $label = empty($payload['sections'])
            ? 'deploy config'
            : 'deploy config + '.count($payload['sections']).' section(s)';

        return self::postToCpanel($templateRequest, $payload, $label);
    }

    /**
     * Push APPROVED content into the advisor's own cPanel MySQL `sections` table.
     */
    public static function pushToAdvisorCpanel(mixed $advisorId, array $sectionsUpdated = []): bool
    {
        $templateRequest = self::findDeployedRequest($advisorId);

        if (! $templateRequest || ! $templateRequest->cpanel_domain) {
            Log::info("No deployed cPanel domain configured for advisor ID {$advisorId}. Skipping cPanel push.");

            return false;
        }

        if (empty($sectionsUpdated)) {
            $sectionsUpdated = self::advisorSectionPayload($advisorId);
        }

        if (empty($sectionsUpdated)) {
            Log::warning("No hub sections to push for advisor ID {$advisorId}. Dashboard editing will be empty until AdvisorSectionService::ensureForAdvisor runs.");

            return false;
        }

        $payload = self::buildBasePayload($templateRequest, $advisorId);
        $payload['sections'] = $sectionsUpdated;

        return self::postToCpanel($templateRequest, $payload, count($sectionsUpdated).' section(s)');
    }

    public static function findDeployedRequest(mixed $advisorId): ?TemplateRequest
    {
        return TemplateRequest::where(function ($q) use ($advisorId) {
            $q->where('advisor_id', $advisorId)
                ->orWhere('assigned_advisor_id', $advisorId);
        })
            ->where('status', 'deployed')
            ->latest()
            ->first();
    }

    public static function buildBasePayload(TemplateRequest $templateRequest, mixed $advisorId = null): array
    {
        $hubApi = self::hubApiUrl();
        $siteUrl = rtrim((string) $templateRequest->cpanel_domain, '/');

        return [
            'api_key' => $templateRequest->cpanel_api_key,
            'advisor_id' => $advisorId,
            'deployment_mode' => 'advisor',
            // Uploads live on the hub that handled the upload (this deploy when remoting from shared).
            'uploads_origin' => self::storageApiUrl(),
            'site_url' => $siteUrl,
            'laravel_api_url' => $hubApi,
            'primary_color' => $templateRequest->primary_color,
            'secondary_color' => $templateRequest->secondary_color,
            'logo_url' => self::brandingAssetForCpanel($templateRequest->logo_url, 'logo'),
            'favicon_url' => self::brandingAssetForCpanel($templateRequest->favicon_url, 'favicon'),
            'db_host' => $templateRequest->cpanel_db_host ?: 'localhost',
            'db_name' => $templateRequest->cpanel_db_name,
            'db_user' => $templateRequest->cpanel_db_user,
            'db_pass' => $templateRequest->cpanel_db_password,
        ];
    }

    /**
     * Make uploaded asset paths loadable from advisor cPanel sites.
     * Relative upload paths are resolved against THIS deploy (where files were stored),
     * not the acting white-label API URL.
     */
    public static function absoluteAssetUrl(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^(https?:|data:|blob:)#i', $path)) {
            return $path;
        }

        $hubApi = self::storageApiUrl();
        $filename = basename(parse_url($path, PHP_URL_PATH) ?: $path);

        if ($filename !== '' && (
            str_contains($path, 'uploaded-images')
            || str_contains($path, '/uploads/')
            || str_starts_with($path, 'uploads/')
        )) {
            return $hubApi.'/website-compliance/uploaded-images/'.$filename;
        }

        if (str_starts_with($path, '/website-compliance/')) {
            return $hubApi.$path;
        }

        if (str_starts_with($path, '/')) {
            return $hubApi.$path;
        }

        return $hubApi.'/'.ltrim($path, '/');
    }

    /**
     * Prefer embedding the file so advisor cPanel does not depend on hub upload routes.
     * Falls back to an absolute URL on the storage hub.
     */
    public static function brandingAssetForCpanel(mixed $path, string $label = 'asset'): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'data:')) {
            return $path;
        }

        $filename = basename(parse_url($path, PHP_URL_PATH) ?: $path);
        if ($filename === '' || $filename === '.' || $filename === '/') {
            return self::absoluteAssetUrl($path);
        }

        $localPaths = [
            storage_path('app/uploads/'.$filename),
            public_path('uploads/'.$filename),
        ];

        foreach ($localPaths as $local) {
            if (! is_file($local) || ! is_readable($local)) {
                continue;
            }

            $size = filesize($local);
            // Keep payload reasonable for cPanel api.php / MySQL TEXT.
            if ($size === false || $size <= 0 || $size > 450000) {
                break;
            }

            $mime = mime_content_type($local) ?: self::guessImageMime($filename);
            $encoded = base64_encode((string) file_get_contents($local));

            Log::info("cPanel branding: embedded {$label} from local file {$filename}");

            return 'data:'.$mime.';base64,'.$encoded;
        }

        // Try fetching from storage candidates (this deploy + acting hub).
        foreach (self::uploadApiCandidates() as $base) {
            $url = rtrim($base, '/').'/website-compliance/uploaded-images/'.$filename;
            try {
                $response = Http::timeout(12)->get($url);
                if (! $response->successful()) {
                    continue;
                }
                $body = $response->body();
                if ($body === '' || strlen($body) > 450000) {
                    return $url;
                }
                $mime = $response->header('Content-Type') ?: self::guessImageMime($filename);
                if (! str_starts_with((string) $mime, 'image/') && ! str_contains((string) $mime, 'icon')) {
                    $mime = self::guessImageMime($filename);
                }
                Log::info("cPanel branding: embedded {$label} from {$url}");

                return 'data:'.$mime.';base64,'.base64_encode($body);
            } catch (\Throwable $e) {
                Log::warning("cPanel branding: fetch failed for {$url}: ".$e->getMessage());
            }
        }

        return self::absoluteAssetUrl($path);
    }

    /**
     * API base for files stored by this Laravel process (shared when remoting).
     */
    public static function storageApiUrl(): string
    {
        $configured = config('services.website_compliance.uploads_origin');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return rtrim((string) config('app.url'), '/').'/api';
    }

    public static function hubApiUrl(): string
    {
        $actingApi = self::actingWhiteLabelApiUrl();
        if ($actingApi) {
            return $actingApi;
        }

        $configured = config('services.website_compliance.hub_api_url');
        if ($configured) {
            return rtrim((string) $configured, '/');
        }

        return self::storageApiUrl();
    }

    public static function hubUploadsOrigin(): string
    {
        return self::storageApiUrl();
    }

    /**
     * @return list<string>
     */
    protected static function uploadApiCandidates(): array
    {
        $candidates = array_filter([
            self::storageApiUrl(),
            self::actingWhiteLabelApiUrl(),
            rtrim((string) config('app.url'), '/').'/api',
            'https://sharedhub.fin-proms.com/api',
            'https://myhub.fin-proms.com/api',
        ]);

        return array_values(array_unique($candidates));
    }

    protected static function guessImageMime(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };
    }

    /**
     * When Power Admin deploys from shared onto a white-label, advisor sites
     * should use that white-label's Laravel API for live content endpoints.
     */
    protected static function actingWhiteLabelApiUrl(): ?string
    {
        try {
            $user = auth('sanctum')->user() ?? auth()->user();
            if (! $user) {
                return null;
            }

            $acting = app(\App\Services\ActingHubService::class);
            if (! $acting->isActingOnWhiteLabel($user)) {
                return null;
            }

            $hub = $acting->actingHub($user);
            if (! $hub) {
                return null;
            }

            if (filled($hub->api_url)) {
                return rtrim((string) $hub->api_url, '/');
            }

            if (filled($hub->frontend_url)) {
                return rtrim((string) $hub->frontend_url, '/').'/api';
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    protected static function postToCpanel(TemplateRequest $templateRequest, array $payload, mixed $label): bool
    {
        foreach (self::advisorApiEndpoints($templateRequest) as $endpoint) {
            try {
                $response = Http::timeout(20)->post($endpoint, $payload);
                $body = $response->json();
                $dbActive = is_array($body) ? ($body['db_active'] ?? false) : false;
                $updatedCount = is_array($body) ? (int) ($body['updated_count'] ?? 0) : 0;
                $configWritten = is_array($body) ? ($body['config_written'] ?? false) : false;
                $statusOk = is_array($body) ? (($body['status'] ?? '') === 'success') : false;

                if ($response->successful() && $statusOk && ($dbActive || $updatedCount > 0 || $configWritten)) {
                    Log::info("cPanel sync ({$label}) OK via {$endpoint}");

                    return true;
                }
                Log::warning('cPanel push ('.$label.') to '.$endpoint.' returned HTTP '.$response->status().' '.$response->body());
            } catch (\Exception $e) {
                Log::error("Failed cPanel sync ({$label}) to {$endpoint}: ".$e->getMessage());
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function advisorApiEndpoints(TemplateRequest $templateRequest): array
    {
        $domain = rtrim((string) $templateRequest->cpanel_domain, '/');
        $slug = preg_replace('/[^a-z0-9_-]/i', '', (string) ($templateRequest->template_name ?: 'template4')) ?: 'template4';

        $origin = preg_replace('#/(template\d+|public)/?$#i', '', $domain) ?: $domain;

        return array_values(array_unique(array_filter([
            "{$domain}/api.php",
            "{$domain}/api.php?action=sync",
            "{$domain}/{$slug}/api.php",
            "{$domain}/public/api.php",
            "{$origin}/{$slug}/api.php",
            "{$origin}/{$slug}/public/api.php",
        ])));
    }

    public static function advisorSectionPayload(mixed $advisorId): array
    {
        $templateRequest = self::findDeployedRequest($advisorId);
        if ($templateRequest) {
            $scoped = self::advisorSectionPayloadForTemplateRequest((int) $templateRequest->id);
            if (! empty($scoped)) {
                return $scoped;
            }
        }

        $rows = Section::where('advisor_id', $advisorId)
            ->whereNotNull('template_request_id')
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            $rows = Section::where('advisor_id', $advisorId)->orderBy('id')->get();
        }

        $payload = [];
        $seen = [];

        foreach ($rows as $sec) {
            $name = $sec->name;
            if (! $name || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $payload[] = [
                'name' => $name,
                'section_key' => $sec->section_key ?: strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name)),
                'display_name' => $sec->display_name ?: $name,
                'is_visible' => $sec->is_visible !== false,
                'content' => $sec->content,
            ];
        }

        return $payload;
    }

    public static function advisorSectionPayloadForTemplateRequest(int $templateRequestId): array
    {
        $rows = Section::where('template_request_id', $templateRequestId)->orderBy('id')->get();
        $payload = [];
        $seen = [];

        foreach ($rows as $sec) {
            $name = $sec->name;
            if (! $name || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $payload[] = [
                'name' => $name,
                'section_key' => $sec->section_key ?: strtolower((string) preg_replace('/[^a-z0-9]/i', '', $name)),
                'display_name' => $sec->display_name ?: $name,
                'is_visible' => $sec->is_visible !== false,
                'content' => $sec->content,
            ];
        }

        return $payload;
    }

    public static function pushToTemplateRequestCpanel(TemplateRequest $templateRequest, array $sectionsUpdated = []): bool
    {
        if (! $templateRequest->cpanel_domain) {
            Log::info('pushToTemplateRequestCpanel: no cpanel_domain on template request #'.$templateRequest->id);

            return false;
        }

        $advisorId = $templateRequest->advisor_id ?? $templateRequest->assigned_advisor_id;

        if (empty($sectionsUpdated)) {
            $sectionsUpdated = self::advisorSectionPayloadForTemplateRequest((int) $templateRequest->id);
        }

        if (empty($sectionsUpdated)) {
            Log::warning("No hub sections to push for template_request #{$templateRequest->id} (advisor {$advisorId}).");

            return false;
        }

        $payload = self::buildBasePayload($templateRequest, $advisorId);
        $payload['sections'] = $sectionsUpdated;

        return self::postToCpanel($templateRequest, $payload, count($sectionsUpdated).' section(s)');
    }
}
