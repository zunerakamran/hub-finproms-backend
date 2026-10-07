<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Models\WebsiteCompliance\TemplateRequest;
use Illuminate\Console\Command;

class SetCpanelApiKey extends Command
{
    protected $signature = 'wc:set-cpanel-api-key
        {template_request_id : Deployed template request id}
        {api_key : Plain SECRET_API_KEY from advisor cpanel-config.php}';

    protected $description = 'Re-save wc_template_requests.cpanel_api_key encrypted with the current APP_KEY (fixes MAC/decrypt failures)';

    public function handle(): int
    {
        $id = (int) $this->argument('template_request_id');
        $plain = trim((string) $this->argument('api_key'));

        if ($plain === '') {
            $this->error('api_key cannot be empty.');

            return self::FAILURE;
        }

        $templateRequest = TemplateRequest::query()->find($id);
        if (! $templateRequest) {
            $this->error("Template request #{$id} not found.");

            return self::FAILURE;
        }

        // Goes through SafeEncrypted cast → encrypts with current APP_KEY.
        $templateRequest->cpanel_api_key = $plain;
        $templateRequest->save();
        $templateRequest->refresh();

        if (! filled($templateRequest->cpanel_api_key)) {
            $this->error('Saved but still unreadable — check APP_KEY is set in .env / config cache.');

            return self::FAILURE;
        }

        if ((string) $templateRequest->cpanel_api_key !== $plain) {
            $this->error('Saved value did not round-trip decrypt to the same key.');

            return self::FAILURE;
        }

        $this->info("Template request #{$id}: cpanel_api_key saved and decrypts OK.");
        $this->line('Next: php artisan wc:diagnose-cpanel '.$id);

        return self::SUCCESS;
    }
}
