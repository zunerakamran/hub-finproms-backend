<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\WebsiteCompliance\CpanelSyncService;
use Illuminate\Console\Command;

class DiagnoseCpanelSync extends Command
{
    protected $signature = 'wc:diagnose-cpanel {template_request_id : Deployed template request id}';

    protected $description = 'Push hub sections to the advisor cPanel site and print the raw sync result (for debugging publish issues)';

    public function handle(): int
    {
        $id = (int) $this->argument('template_request_id');
        $templateRequest = TemplateRequest::query()->find($id);

        if (! $templateRequest) {
            $this->error("Template request #{$id} not found.");

            return self::FAILURE;
        }

        $this->info("Template request #{$templateRequest->id}");
        $this->line('  status: '.$templateRequest->status);
        $this->line('  cpanel_domain: '.($templateRequest->cpanel_domain ?: '(empty)'));
        $this->line('  api_key set: '.(filled($templateRequest->cpanel_api_key) ? 'yes' : 'NO'));
        $this->line('  advisor_id: '.($templateRequest->advisor_id ?? 'null'));
        $this->line('  assigned_advisor_id: '.($templateRequest->assigned_advisor_id ?? 'null'));

        $sections = CpanelSyncService::advisorSectionPayloadForTemplateRequest((int) $templateRequest->id);
        $this->line('  hub sections for TR: '.count($sections));
        if ($sections !== []) {
            $this->line('  first section: '.($sections[0]['name'] ?? '?'));
            $content = $sections[0]['content'] ?? null;
            $this->line('  first content type: '.(is_array($content) ? 'array/object' : gettype($content)));
        }

        $this->newLine();
        $this->info('Pushing to cPanel…');

        $result = CpanelSyncService::pushToTemplateRequestCpanelWithDetails($templateRequest, $sections);

        $this->line('  ok: '.(($result['ok'] ?? false) ? 'YES' : 'NO'));
        $this->line('  endpoint: '.($result['endpoint'] ?? 'null'));
        $this->line('  http_status: '.($result['http_status'] ?? 'null'));
        $this->line('  message: '.($result['message'] ?? ''));

        $body = $result['body'] ?? null;
        if (is_array($body)) {
            $this->line('  body.status: '.($body['status'] ?? ''));
            $this->line('  body.db_active: '.json_encode($body['db_active'] ?? null));
            $this->line('  body.updated_count: '.json_encode($body['updated_count'] ?? null));
            $this->line('  body.config_written: '.json_encode($body['config_written'] ?? null));
            $this->line('  body.message: '.($body['message'] ?? ''));
        } elseif (is_string($body)) {
            $this->line('  body (raw): '.mb_substr($body, 0, 300));
        }

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
