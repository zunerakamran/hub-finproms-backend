<?php

namespace App\Services\WebsiteCompliance;

use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\Template;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use App\Support\WebsiteCompliance\TemplateDefaultContent;
use Illuminate\Support\Facades\Log;

/**
 * Create / fill advisor-owned hub sections.
 *
 * Source of truth for new advisor rows: showcase sections (advisor_id IS NULL).
 * JSON / dummy_content is only used if the hub has no showcase rows yet.
 */
class AdvisorSectionService
{
    /**
     * Ensure every showcase section exists for this advisor on the home page.
     *
     * @param  bool  $overwrite  When true (deploy), copy showcase content onto existing advisor rows.
     *                           When false (dashboard open), never overwrite advisor edits.
     * @return int Number of rows created or updated
     */
    public static function ensureForAdvisor(
        int $advisorId,
        ?string $templateSlug = null,
        bool $overwrite = false,
        ?int $templateRequestId = null
    ): int {
        if ($advisorId <= 0) {
            Log::warning('AdvisorSectionService: skipped, invalid advisor id');

            return 0;
        }

        $slug = self::normalizeSlug($templateSlug);
        $fallbackSlug = HubTemplateCatalog::defaultSlug();
        $template = Template::where('slug', $slug)->first()
            ?? ($fallbackSlug ? Template::where('slug', $fallbackSlug)->first() : null)
            ?? Template::first();

        if (! $template) {
            Log::warning("AdvisorSectionService: no template found for slug {$slug}");

            return 0;
        }

        $page = Page::firstOrCreate(
            ['slug' => 'home'],
            ['title' => 'Home Page', 'template_id' => $template->id]
        );
        if (! $page->template_id) {
            $page->update(['template_id' => $template->id]);
        }

        $showcaseRows = self::showcaseRows((int) $page->id);
        $source = 'showcase';

        if ($showcaseRows->isEmpty()) {
            $source = 'json';
            $defaults = TemplateDefaultContent::forTemplate($template, $slug);
            if (empty($defaults)) {
                Log::warning("AdvisorSectionService: no showcase rows and no default JSON for advisor {$advisorId}");

                return 0;
            }
            $changed = self::upsertFromDefaults(
                $page->id,
                $advisorId,
                $template->id,
                $defaults,
                $overwrite,
                $templateRequestId
            );
        } else {
            $changed = self::upsertFromShowcase($advisorId, $template->id, $showcaseRows, $overwrite, $templateRequestId);
        }

        $totalQuery = Section::where('advisor_id', $advisorId);
        if ($templateRequestId === null) {
            $totalQuery->whereNull('template_request_id');
        } else {
            $totalQuery->where('template_request_id', $templateRequestId);
        }
        $total = $totalQuery->count();

        Log::info(
            "AdvisorSectionService: advisor {$advisorId} template_request_id="
            .($templateRequestId === null ? 'NULL' : $templateRequestId)
            ." source={$source} changed={$changed} hub_total={$total} overwrite=".($overwrite ? '1' : '0')
        );

        return $changed;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Section>
     */
    private static function showcaseRows(int $pageId)
    {
        $rows = Section::where('page_id', $pageId)
            ->whereNull('advisor_id')
            ->orderBy('id')
            ->get();

        $unique = collect();
        foreach ($rows as $row) {
            $name = $row->name;
            if (! $name || $unique->has($name)) {
                continue;
            }
            $unique->put($name, $row);
        }

        return $unique->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Section>  $showcaseRows
     */
    private static function upsertFromShowcase(
        int $advisorId,
        ?int $templateId,
        $showcaseRows,
        bool $overwrite,
        ?int $templateRequestId
    ): int {
        $changed = 0;

        foreach ($showcaseRows as $showcase) {
            $existingQuery = Section::where('page_id', $showcase->page_id)
                ->where('name', $showcase->name)
                ->where('advisor_id', $advisorId);

            if ($templateRequestId === null) {
                $existingQuery->whereNull('template_request_id');
            } else {
                $existingQuery->where('template_request_id', $templateRequestId);
            }

            $existing = $existingQuery->first();

            $payload = self::payloadFromShowcase($showcase, $advisorId, $templateId, $templateRequestId);

            if (! $existing && $templateRequestId) {
                $hubTwin = Section::where('page_id', $showcase->page_id)
                    ->where('name', $showcase->name)
                    ->where('advisor_id', $advisorId)
                    ->whereNull('template_request_id')
                    ->orderBy('id')
                    ->first();
                if ($hubTwin && ! self::isEmptyContent($hubTwin->content)) {
                    $payload['content'] = is_string($hubTwin->content)
                        ? $hubTwin->content
                        : TemplateDefaultContent::encode($hubTwin->content);
                    if ($hubTwin->display_name) {
                        $payload['display_name'] = $hubTwin->display_name;
                    }
                    if ($hubTwin->is_visible !== null) {
                        $payload['is_visible'] = $hubTwin->is_visible !== false;
                    }
                }
            }

            if ($existing) {
                if ($overwrite || self::isEmptyContent($existing->content)) {
                    if (
                        self::isEmptyContent($existing->content)
                        && $templateRequestId
                        && ! $overwrite
                    ) {
                        $hubTwin = Section::where('page_id', $showcase->page_id)
                            ->where('name', $showcase->name)
                            ->where('advisor_id', $advisorId)
                            ->whereNull('template_request_id')
                            ->orderBy('id')
                            ->first();
                        if ($hubTwin && ! self::isEmptyContent($hubTwin->content)) {
                            $payload['content'] = is_string($hubTwin->content)
                                ? $hubTwin->content
                                : TemplateDefaultContent::encode($hubTwin->content);
                        }
                    }
                    $existing->update($payload);
                    $changed++;
                }
                continue;
            }

            try {
                Section::create($payload);
                $changed++;
            } catch (\Throwable $e) {
                Log::error("AdvisorSectionService: failed creating section {$showcase->name} for advisor {$advisorId}: ".$e->getMessage());
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private static function upsertFromDefaults(
        int $pageId,
        int $advisorId,
        ?int $templateId,
        array $defaults,
        bool $overwrite,
        ?int $templateRequestId
    ): int {
        $changed = 0;

        foreach ($defaults as $sName => $sContent) {
            if ($sName === '' || $sName === null) {
                continue;
            }

            $existingQuery = Section::where('page_id', $pageId)
                ->where('name', $sName)
                ->where('advisor_id', $advisorId);

            if ($templateRequestId === null) {
                $existingQuery->whereNull('template_request_id');
            } else {
                $existingQuery->where('template_request_id', $templateRequestId);
            }

            $existing = $existingQuery->first();

            $payload = self::buildPayload($pageId, $advisorId, $templateId, (string) $sName, $sContent, $templateRequestId);

            if ($existing) {
                if ($overwrite || self::isEmptyContent($existing->content)) {
                    $existing->update($payload);
                    $changed++;
                }
                continue;
            }

            try {
                Section::create($payload);
                $changed++;
            } catch (\Throwable $e) {
                Log::error("AdvisorSectionService: failed creating section {$sName} for advisor {$advisorId}: ".$e->getMessage());
            }
        }

        return $changed;
    }

    private static function payloadFromShowcase(
        Section $showcase,
        int $advisorId,
        ?int $templateId,
        ?int $templateRequestId
    ): array {
        return [
            'page_id' => $showcase->page_id,
            'template_id' => $showcase->template_id ?: $templateId,
            'advisor_id' => $advisorId,
            'template_request_id' => $templateRequestId,
            'name' => $showcase->name,
            'section_key' => $showcase->section_key
                ?: strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $showcase->name)),
            'display_name' => $showcase->display_name ?: $showcase->name,
            'is_visible' => $showcase->is_visible !== false,
            'content' => is_string($showcase->content)
                ? $showcase->content
                : TemplateDefaultContent::encode($showcase->content),
            'is_locked' => false,
            'locked_by' => null,
        ];
    }

    private static function normalizeSlug(?string $slug): string
    {
        $raw = strtolower(trim((string) $slug));
        $fallback = HubTemplateCatalog::defaultSlug() ?? 'template4';
        if ($raw === '') {
            return $fallback;
        }
        if (str_contains($raw, 'template4')) {
            return HubTemplateCatalog::allows('template4') ? 'template4' : $fallback;
        }
        $safe = HubTemplateCatalog::sanitizeSlug($raw);

        return $safe !== '' ? $safe : $fallback;
    }

    private static function buildPayload(
        int $pageId,
        int $advisorId,
        ?int $templateId,
        string $sName,
        mixed $sContent,
        ?int $templateRequestId
    ): array {
        return [
            'page_id' => $pageId,
            'template_id' => $templateId,
            'advisor_id' => $advisorId,
            'template_request_id' => $templateRequestId,
            'name' => $sName,
            'section_key' => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $sName)),
            'display_name' => $sName,
            'is_visible' => true,
            'content' => TemplateDefaultContent::encode($sContent),
            'is_locked' => false,
            'locked_by' => null,
        ];
    }

    private static function isEmptyContent(mixed $content): bool
    {
        if ($content === null || $content === '') {
            return true;
        }
        if (is_string($content) && trim($content) === '') {
            return true;
        }
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if ($decoded === null && trim($content) !== '') {
                return false;
            }
            if (is_array($decoded)) {
                return count($decoded) === 0;
            }
        }
        if (is_array($content)) {
            return count($content) === 0;
        }

        return empty($content);
    }
}
