<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Casts\SafeEncrypted;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\WebsiteCompliance\CpanelSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Throwable;

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
        $this->line('  advisor_id: '.($templateRequest->advisor_id ?? 'null'));
        $this->line('  assigned_advisor_id: '.($templateRequest->assigned_advisor_id ?? 'null'));

        $this->describeSecret($templateRequest, 'cpanel_api_key', 'api_key');
        $this->describeSecret($templateRequest, 'cpanel_db_password', 'db_password');

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

        if ($templateRequest->cpanelSecretDecryptFailed('cpanel_api_key')) {
            $this->newLine();
            $this->error('FIX: cpanel_api_key is in the DB but cannot be decrypted with the current APP_KEY.');
            $this->line('Open Website Compliance → this deployment → Update deployment, and re-enter');
            $this->line('the same SECRET_API_KEY from the advisor site cpanel-config.php, then save.');
            $this->line('Then re-run: php artisan wc:diagnose-cpanel '.$id);
        }

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    private function describeSecret(TemplateRequest $templateRequest, string $attribute, string $label): void
    {
        $raw = $templateRequest->getAttributes()[$attribute]
            ?? $templateRequest->getRawOriginal($attribute)
            ?? null;
        $rawLen = is_string($raw) ? strlen($raw) : 0;
        $looksEncrypted = is_string($raw) && $raw !== '' && SafeEncrypted::looksLikeLaravelCiphertext($raw);
        $readable = filled($templateRequest->{$attribute});

        $decryptNote = 'empty';
        if ($rawLen > 0) {
            if ($readable) {
                $decryptNote = 'decrypt OK (usable)';
            } elseif ($looksEncrypted) {
                $decryptNote = 'CIPHERTEXT PRESENT but decrypt FAILED (APP_KEY mismatch)';
                try {
                    Crypt::decryptString((string) $raw);
                } catch (Throwable $e) {
                    $decryptNote .= ' — '.$e->getMessage();
                }
            } else {
                $decryptNote = 'raw value present (legacy plaintext path)';
            }
        }

        $this->line("  {$label} in DB: ".($rawLen > 0 ? "yes ({$rawLen} chars)" : 'NO'));
        $this->line("  {$label} readable: ".($readable ? 'yes' : 'NO').' — '.$decryptNote);
    }
}
