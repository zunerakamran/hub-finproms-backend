<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use Illuminate\Console\Command;

class SeedAdvisorSections extends Command
{
    protected $signature = 'wc:seed-advisor-sections {advisor_id?} {--overwrite : Replace existing advisor content with showcase content}';

    protected $description = 'Create hub sections for an advisor by copying showcase (advisor_id NULL) rows';

    public function handle(): int
    {
        $arg = $this->argument('advisor_id');
        $ids = [];

        if ($arg !== null && $arg !== '') {
            $ids[] = (int) $arg;
        } else {
            $ids = TemplateRequest::query()
                ->get()
                ->map(function ($row) {
                    return $row->advisor_id ?? $row->assigned_advisor_id;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (empty($ids)) {
            $this->warn('No advisor ids found. Pass an advisor_id or create a template request first.');

            return self::FAILURE;
        }

        foreach ($ids as $advisorId) {
            $slug = optional(
                TemplateRequest::where('advisor_id', $advisorId)
                    ->orWhere('assigned_advisor_id', $advisorId)
                    ->latest()
                    ->first()
            )->template_name ?: 'template4';

            $created = AdvisorSectionService::ensureForAdvisor(
                (int) $advisorId,
                $slug,
                (bool) $this->option('overwrite')
            );
            $total = Section::where('advisor_id', $advisorId)->count();
            $this->info("Advisor {$advisorId}: created/filled {$created}, hub total {$total}");
        }

        return self::SUCCESS;
    }
}
