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
            'uploads_origin' => self::hubUploadsOrigin(),
            'site_url' => $siteUrl,
            'laravel_api_url' => $hubApi,
            'primary_color' => $templateRequest->primary_color,
            'secondary_color' => $templateRequest->secondary_color,
            'logo_url' => $templateRequest->logo_url,
            'db_host' => $templateRequest->cpanel_db_host ?: 'localhost',
            'db_name' => $templateRequest->cpanel_db_name,
            'db_user' => $templateRequest->cpanel_db_user,
            'db_pass' => $templateRequest->cpanel_db_password,
        ];
    }

    public static function hubApiUrl(): string
    {
        $configured = config('services.website_compliance.hub_api_url');
        if ($configured) {
            return rtrim((string) $configured, '/');
        }

        return rtrim((string) config('app.url'), '/').'/api';
    }

    public static function hubUploadsOrigin(): string
    {
        $configured = config('services.website_compliance.uploads_origin');
        if ($configured) {
            return rtrim((string) $configured, '/');
        }

        return self::hubApiUrl();
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
