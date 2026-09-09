<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class HubService
{
    public function current(): Hub
    {
        $slug = (string) config('hub.current_slug', 'shared');

        return Cache::remember("hub:current:{$slug}", 60, function () use ($slug) {
            $hub = Hub::query()->where('slug', $slug)->where('is_active', true)->first();

            if ($hub) {
                return $hub;
            }

            // Fallback: ensure shared hub always exists for this codebase.
            return Hub::query()->firstOrCreate(
                ['slug' => 'shared'],
                [
                    'name' => 'Shared Hub',
                    'type' => Hub::TYPE_SHARED,
                    'is_active' => true,
                    'primary_color' => null,
                    'secondary_color' => null,
                    'logo_url' => null,
                    'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
                ]
            );
        });
    }

    public function forgetCurrentCache(): void
    {
        $slug = (string) config('hub.current_slug', 'shared');
        Cache::forget("hub:current:{$slug}");
    }

    public function can(string $flag): bool
    {
        return $this->current()->can($flag);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, bool>
     */
    public function sanitizeChecklist(array $input, string $type = Hub::TYPE_WHITE_LABEL): array
    {
        $defaults = Hub::defaultChecklist($type);
        $clean = [];

        foreach (array_keys(Hub::CHECKLIST_DEFINITIONS) as $key) {
            if (array_key_exists($key, $input)) {
                $clean[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            } else {
                $clean[$key] = $defaults[$key];
            }
        }

        return $this->applyExclusivity($clean, array_keys($input));
    }

    /**
     * Merge partial checklist updates onto an existing hub.
     *
     * @param  array<string, mixed>  $partial
     * @return array<string, bool>
     */
    public function mergeChecklist(Hub $hub, array $partial): array
    {
        $current = $hub->resolvedChecklist();

        foreach (array_keys(Hub::CHECKLIST_DEFINITIONS) as $key) {
            if (array_key_exists($key, $partial)) {
                $current[$key] = filter_var($partial[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $this->applyExclusivity($current, array_keys($partial));
    }

    /**
     * When a flag is enabled, force its opposite off.
     * If both are true, prefer keys that were explicitly updated (last preferred wins).
     *
     * @param  array<string, bool>  $checklist
     * @param  list<int|string>  $preferredKeys
     * @return array<string, bool>
     */
    public function applyExclusivity(array $checklist, array $preferredKeys = []): array
    {
        $preferred = array_values(array_filter(
            array_map('strval', $preferredKeys),
            fn (string $key) => array_key_exists($key, Hub::CHECKLIST_OPPOSITES)
        ));

        // Process preferred keys last so their "on" state wins over the opposite.
        $keys = array_unique([...array_keys(Hub::CHECKLIST_OPPOSITES), ...$preferred]);

        foreach ($keys as $key) {
            $opposite = Hub::CHECKLIST_OPPOSITES[$key] ?? null;
            if (! $opposite) {
                continue;
            }

            if (! empty($checklist[$key]) && ! empty($checklist[$opposite])) {
                $preferKey = in_array($key, $preferred, true);
                $preferOpposite = in_array($opposite, $preferred, true);

                if ($preferKey && ! $preferOpposite) {
                    $checklist[$opposite] = false;
                } elseif ($preferOpposite && ! $preferKey) {
                    $checklist[$key] = false;
                } elseif ($preferKey && $preferOpposite) {
                    // Both updated: keep the later preferred key enabled.
                    $last = null;
                    foreach ($preferred as $candidate) {
                        if ($candidate === $key || $candidate === $opposite) {
                            $last = $candidate;
                        }
                    }
                    if ($last === $key) {
                        $checklist[$opposite] = false;
                    } else {
                        $checklist[$key] = false;
                    }
                } else {
                    // Neither preferred: keep canonical first of the pair.
                    $pair = [$key, $opposite];
                    sort($pair);
                    $checklist[$pair[1]] = false;
                }
            }
        }

        // Also: when enabling a preferred key, always clear opposite even if only one was true after merge.
        foreach ($preferred as $key) {
            $opposite = Hub::CHECKLIST_OPPOSITES[$key] ?? null;
            if ($opposite && ! empty($checklist[$key])) {
                $checklist[$opposite] = false;
            }
        }

        return $checklist;
    }

    public function assertCan(string $flag, string $message): void
    {
        if (! $this->can($flag)) {
            throw new RuntimeException($message);
        }
    }
}
