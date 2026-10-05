<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class HubService
{
    public function current(): Hub
    {
        $slug = (string) config('hub.current_slug', 'shared');

        // Cache only the id (not the Eloquent model) — safer with encrypted db_* fields
        // and avoids stale serialized models. Fall back to DB if cache is misconfigured.
        try {
            $hubId = Cache::remember("hub:current:{$slug}", 300, function () use ($slug) {
                return $this->resolveCurrentFromDatabase($slug)->id;
            });

            if ($hubId) {
                $hub = Hub::query()->find($hubId);
                if ($hub) {
                    return $hub;
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->resolveCurrentFromDatabase($slug);
    }

    /**
     * Load (and heal) the current deploy hub from the database.
     */
    private function resolveCurrentFromDatabase(string $slug): Hub
    {
        // Schema healing only on cache miss / cache-bypass path.
        $this->ensureRemoteDatabaseColumns();

        $hub = Hub::query()->where('slug', $slug)->where('is_active', true)->first();

        // Inactive / missing row for this slug — create or reactivate.
        if (! $hub) {
            $hub = Hub::query()->where('slug', $slug)->first();
        }

        $type = $this->resolveDeployType($slug);

        if ($hub) {
            // Heal: Central .env was set but hubs.type was never promoted.
            if ($type === Hub::TYPE_CENTRAL && $hub->type !== Hub::TYPE_CENTRAL) {
                $hub->forceFill([
                    'type' => Hub::TYPE_CENTRAL,
                    'is_active' => true,
                    'name' => $hub->name === '' || $hub->name === 'Shared Hub'
                        ? 'Central Hub Controller'
                        : $hub->name,
                    'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
                    'role_capabilities' => app(CapabilitiesMatrixService::class)
                        ->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
                ])->save();
                $hub = $hub->fresh() ?? $hub;
            } elseif ($type === Hub::TYPE_CENTRAL
                && (! is_array($hub->role_capabilities) || $hub->role_capabilities === [])
            ) {
                // Fresh Central row with empty matrix — seed Power Admin defaults.
                $hub->forceFill([
                    'role_capabilities' => app(CapabilitiesMatrixService::class)
                        ->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
                ])->save();
                $hub = $hub->fresh() ?? $hub;
            } elseif (! $hub->is_active) {
                $hub->forceFill(['is_active' => true])->save();
                $hub = $hub->fresh() ?? $hub;
            }

            return $hub;
        }

        $name = match ($type) {
            Hub::TYPE_CENTRAL => 'Central Hub Controller',
            Hub::TYPE_SHARED => $slug === 'shared' ? 'Shared Hub' : 'Hub',
            default => 'Hub',
        };

        return Hub::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'type' => $type,
                'is_active' => true,
                'primary_color' => null,
                'secondary_color' => null,
                'accent_color' => null,
                'logo_url' => null,
                'white_logo_url' => null,
                'favicon_url' => null,
                'checklist' => Hub::defaultChecklist($type),
            ]
        );
    }

    /**
     * Resolve hub type for THIS deploy from HUB_TYPE / HUB_SLUG.
     */
    public function resolveDeployType(string $slug): string
    {
        $configuredType = strtolower(trim((string) config('hub.type', '')));

        return match (true) {
            in_array($configuredType, Hub::TYPES, true) => $configuredType,
            $slug === 'central' => Hub::TYPE_CENTRAL,
            $slug === 'shared' || str_starts_with($slug, 'shared-') => Hub::TYPE_SHARED,
            default => Hub::TYPE_WHITE_LABEL,
        };
    }

    public function forgetCurrentCache(): void
    {
        $slug = (string) config('hub.current_slug', 'shared');
        try {
            Cache::forget("hub:current:{$slug}");
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Ensure remote-DB wiring columns exist (older Central deploys may have
     * missed the migration — writing db_password then 500s with Unknown column).
     */
    public function ensureRemoteDatabaseColumns(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            if (! Schema::hasTable('hubs')) {
                return;
            }

            $columns = [
                'db_driver' => fn (Blueprint $table) => $table->string('db_driver', 32)->nullable(),
                'db_host' => fn (Blueprint $table) => $table->string('db_host')->nullable(),
                'db_port' => fn (Blueprint $table) => $table->unsignedSmallInteger('db_port')->nullable(),
                'db_database' => fn (Blueprint $table) => $table->string('db_database')->nullable(),
                'db_username' => fn (Blueprint $table) => $table->string('db_username')->nullable(),
                'db_password' => fn (Blueprint $table) => $table->text('db_password')->nullable(),
                'db_ssl_mode' => fn (Blueprint $table) => $table->string('db_ssl_mode', 32)->nullable(),
                'db_ssl_ca' => fn (Blueprint $table) => $table->text('db_ssl_ca')->nullable(),
            ];

            foreach ($columns as $name => $add) {
                if (Schema::hasColumn('hubs', $name)) {
                    continue;
                }
                Schema::table('hubs', function (Blueprint $table) use ($add) {
                    $add($table);
                });
            }
        } catch (Throwable $e) {
            // Do not break Power Admin if ALTER is denied; save will surface a clear error.
            report($e);
            $checked = false;
        }
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

        $clean['module_central_hub'] = $type === Hub::TYPE_CENTRAL;
        $clean['module_shared_hub'] = $type === Hub::TYPE_SHARED;
        $clean['module_white_label_hub'] = $type === Hub::TYPE_WHITE_LABEL;
        if ($type === Hub::TYPE_CENTRAL) {
            $clean['module_shared_hub'] = false;
            $clean['module_white_label_hub'] = false;
        }
        $clean = Hub::applyModuleDependencyRules($clean, $type);

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
            if (Hub::isLockedModuleKey($key)) {
                continue;
            }
            if (array_key_exists($key, $partial)) {
                $current[$key] = filter_var($partial[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $current = $hub->applyModuleDependencies($current);

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
