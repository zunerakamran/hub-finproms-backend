<?php

namespace App\Services;

use App\Models\Hub;
use App\Support\EmailTemplateCatalog;
use InvalidArgumentException;

class EmailTemplateService
{
    public function __construct(
        private readonly WhiteLabelHubSyncService $whiteLabelSync
    ) {}

    /**
     * Events available for this hub based on functionalities / modules.
     *
     * @return list<array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   group: string,
     *   audiences: list<array{key: string, label: string, is_customized: bool}>,
     *   variables: list<array{key: string, label: string}>
     * }>
     */
    public function listEvents(Hub $hub): array
    {
        $checklist = $hub->resolvedChecklist();
        $stored = $this->storedTemplates($hub);
        $items = [];

        foreach (EmailTemplateCatalog::events() as $key => $meta) {
            if (! $this->isEventEnabled($hub, $meta, $checklist)) {
                continue;
            }

            $audiences = [];
            foreach ($meta['audiences'] as $audience) {
                $audiences[] = [
                    'key' => $audience,
                    'label' => $audience === EmailTemplateCatalog::AUDIENCE_ADMIN
                        ? 'Admin mail'
                        : 'User mail',
                    'is_customized' => isset($stored[$key][$audience]) && is_array($stored[$key][$audience]),
                ];
            }

            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $meta['group'],
                'audiences' => $audiences,
                'variables' => $meta['variables'],
            ];
        }

        return $items;
    }

    /**
     * @return array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   group: string,
     *   variables: list<array{key: string, label: string}>,
     *   templates: array<string, array{audience: string, audience_label: string, is_customized: bool, subject: string, eyebrow: string, heading: string, intro: string, closing: ?string, cta_label: ?string, defaults: array<string, mixed>}>
     * }
     */
    public function showEvent(Hub $hub, string $eventKey): array
    {
        $meta = EmailTemplateCatalog::event($eventKey);
        if ($meta === null) {
            throw new InvalidArgumentException('Unknown email event.');
        }

        $checklist = $hub->resolvedChecklist();
        if (! $this->isEventEnabled($hub, $meta, $checklist)) {
            throw new InvalidArgumentException('This email event is not enabled for this hub.');
        }

        $templates = [];
        foreach ($meta['audiences'] as $audience) {
            $resolved = $this->resolvedContent($hub, $eventKey, $audience);
            $defaults = EmailTemplateCatalog::defaults($eventKey, $audience);
            $templates[$audience] = [
                'audience' => $audience,
                'audience_label' => $audience === EmailTemplateCatalog::AUDIENCE_ADMIN
                    ? 'Admin mail'
                    : 'User mail',
                'is_customized' => $resolved['is_customized'],
                'subject' => $resolved['subject'],
                'eyebrow' => $resolved['eyebrow'],
                'heading' => $resolved['heading'],
                'intro' => $resolved['intro'],
                'closing' => $resolved['closing'],
                'cta_label' => $resolved['cta_label'],
                'defaults' => $defaults,
            ];
        }

        return [
            'key' => $eventKey,
            'label' => $meta['label'],
            'description' => $meta['description'],
            'group' => $meta['group'],
            'variables' => $meta['variables'],
            'templates' => $templates,
            'admin_recipients_note' => 'Admin mail is sent to roles with “Receive admin emails” enabled in the Capabilities matrix.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{audience: string, audience_label: string, is_customized: bool, subject: string, eyebrow: string, heading: string, intro: string, closing: ?string, cta_label: ?string, defaults: array<string, mixed>}
     */
    public function update(Hub $hub, string $eventKey, string $audience, array $payload): array
    {
        $meta = EmailTemplateCatalog::event($eventKey);
        if ($meta === null) {
            throw new InvalidArgumentException('Unknown email event.');
        }
        if (! in_array($audience, $meta['audiences'], true)) {
            throw new InvalidArgumentException('This audience is not available for this email event.');
        }

        $checklist = $hub->resolvedChecklist();
        if (! $this->isEventEnabled($hub, $meta, $checklist)) {
            throw new InvalidArgumentException('This email event is not enabled for this hub.');
        }

        $defaults = EmailTemplateCatalog::defaults($eventKey, $audience);
        $content = [];
        foreach (EmailTemplateCatalog::EDITABLE_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                $content[$key] = $defaults[$key];
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                $content[$key] = in_array($key, ['closing', 'cta_label'], true) ? null : ($defaults[$key] ?? '');
                continue;
            }
            if (! is_string($value)) {
                throw new InvalidArgumentException("Field \"{$key}\" must be a string.");
            }
            $content[$key] = $value;
        }

        $stored = $this->storedTemplates($hub);
        $stored[$eventKey][$audience] = $content;
        $hub->email_templates = $stored;
        $hub->save();

        $this->syncWhiteLabelIfNeeded($hub);

        return $this->showEvent($hub->fresh(), $eventKey)['templates'][$audience];
    }

    /**
     * @return array{audience: string, audience_label: string, is_customized: bool, subject: string, eyebrow: string, heading: string, intro: string, closing: ?string, cta_label: ?string, defaults: array<string, mixed>}
     */
    public function reset(Hub $hub, string $eventKey, string $audience): array
    {
        $meta = EmailTemplateCatalog::event($eventKey);
        if ($meta === null) {
            throw new InvalidArgumentException('Unknown email event.');
        }
        if (! in_array($audience, $meta['audiences'], true)) {
            throw new InvalidArgumentException('This audience is not available for this email event.');
        }

        $stored = $this->storedTemplates($hub);
        if (isset($stored[$eventKey][$audience])) {
            unset($stored[$eventKey][$audience]);
            if ($stored[$eventKey] === []) {
                unset($stored[$eventKey]);
            }
            $hub->email_templates = $stored === [] ? null : $stored;
            $hub->save();
            $this->syncWhiteLabelIfNeeded($hub);
        }

        return $this->showEvent($hub->fresh(), $eventKey)['templates'][$audience];
    }

    /**
     * Resolve editable copy for sending (with {{placeholders}} replaced).
     *
     * @param  array<string, scalar|null>  $variables
     * @return array{subject: string, eyebrow: string, heading: string, intro: string, closing: ?string, cta_label: ?string}
     */
    public function resolve(Hub $hub, string $eventKey, string $audience, array $variables = []): array
    {
        $content = $this->resolvedContent($hub, $eventKey, $audience);

        return [
            'subject' => $this->interpolate($content['subject'], $variables),
            'eyebrow' => $this->interpolate($content['eyebrow'], $variables),
            'heading' => $this->interpolate($content['heading'], $variables),
            'intro' => $this->interpolate($content['intro'], $variables),
            'closing' => $content['closing'] !== null
                ? $this->interpolate($content['closing'], $variables)
                : null,
            'cta_label' => $content['cta_label'] !== null
                ? $this->interpolate($content['cta_label'], $variables)
                : null,
        ];
    }

    /**
     * @return array{subject: string, eyebrow: string, heading: string, intro: string, closing: ?string, cta_label: ?string, is_customized: bool}
     */
    public function resolvedContent(Hub $hub, string $eventKey, string $audience): array
    {
        $defaults = EmailTemplateCatalog::defaults($eventKey, $audience);
        $stored = $this->storedTemplates($hub);
        $override = $stored[$eventKey][$audience] ?? null;
        $isCustomized = is_array($override);

        $merged = $defaults;
        if ($isCustomized) {
            foreach (EmailTemplateCatalog::EDITABLE_KEYS as $key) {
                if (array_key_exists($key, $override)) {
                    $merged[$key] = $override[$key];
                }
            }
        }

        return [
            ...$merged,
            'is_customized' => $isCustomized,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function interpolate(string $text, array $variables): string
    {
        $replacements = [];
        foreach ($variables as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) ($value ?? '');
        }

        return strtr($text, $replacements);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $checklist
     */
    private function isEventEnabled(Hub $hub, array $meta, array $checklist): bool
    {
        if (! empty($meta['requires_module'])) {
            $moduleKey = (string) $meta['requires_module'];
            if (empty($checklist[$moduleKey])) {
                return false;
            }
        }

        if (! empty($meta['requires_private']) && ! $hub->isPrivateInviteOnly()) {
            return false;
        }

        if (! empty($meta['requires_public']) && ! $hub->isPublicSubscribe()) {
            return false;
        }

        $requiresAny = $meta['requires_any'] ?? null;
        if (is_array($requiresAny) && $requiresAny !== []) {
            $any = false;
            foreach ($requiresAny as $key) {
                if (! empty($checklist[$key])) {
                    $any = true;
                    break;
                }
            }
            if (! $any) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function storedTemplates(Hub $hub): array
    {
        $stored = $hub->email_templates;

        return is_array($stored) ? $stored : [];
    }

    private function syncWhiteLabelIfNeeded(Hub $hub): void
    {
        if ($hub->isShared() || ! $hub->hasRemoteDatabaseConfigured()) {
            return;
        }

        try {
            $this->whiteLabelSync->pushSettings($hub);
        } catch (InvalidArgumentException) {
            // Editing templates on the control plane should not fail hard if
            // remote wiring is incomplete; local save already succeeded.
        }
    }
}
