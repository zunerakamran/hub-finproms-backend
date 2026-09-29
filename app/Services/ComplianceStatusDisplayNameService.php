<?php

namespace App\Services;

use App\Models\Hub;
use InvalidArgumentException;

class ComplianceStatusDisplayNameService
{
    public function __construct(
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
        private readonly HubService $hubs
    ) {}

    /**
     * @return array<string, string>
     */
    public function labels(Hub $hub): array
    {
        return $hub->resolvedComplianceStatusLabels();
    }

    public function label(Hub $hub, string $status): string
    {
        return $hub->complianceStatusLabel($status);
    }

    /**
     * @return list<array{key: string, label: string, default_label: string}>
     */
    public function editableStatuses(Hub $hub): array
    {
        $labels = $this->labels($hub);
        $statuses = [];
        foreach (Hub::COMPLIANCE_STATUS_LABELS as $key => $default) {
            $statuses[] = [
                'key' => $key,
                'label' => $labels[$key] ?? $default,
                'default_label' => $default,
            ];
        }

        return $statuses;
    }

    /**
     * @param  array<string, mixed>  $names
     * @return array{statuses: list<array{key: string, label: string, default_label: string}>, compliance_status_labels: array<string, string>}
     */
    public function update(Hub $hub, array $names): array
    {
        $cleaned = [];
        foreach (array_keys(Hub::COMPLIANCE_STATUS_LABELS) as $key) {
            if (! array_key_exists($key, $names)) {
                continue;
            }
            $value = trim((string) $names[$key]);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 100) {
                throw new InvalidArgumentException("Display name for \"{$key}\" must be at most 100 characters.");
            }
            $default = Hub::COMPLIANCE_STATUS_LABELS[$key];
            if ($value === $default) {
                continue;
            }
            $cleaned[$key] = $value;
        }

        $existing = is_array($hub->compliance_status_display_names)
            ? $hub->compliance_status_display_names
            : [];
        foreach (array_keys(Hub::COMPLIANCE_STATUS_LABELS) as $key) {
            if (array_key_exists($key, $names)) {
                if (isset($cleaned[$key])) {
                    $existing[$key] = $cleaned[$key];
                } else {
                    unset($existing[$key]);
                }
            }
        }

        $hub->compliance_status_display_names = $existing === [] ? null : $existing;
        $hub->save();
        $this->hubs->forgetCurrentCache();

        if ($hub->isWhiteLabel() && $hub->hasRemoteDatabaseConfigured()) {
            $this->whiteLabelSync->pushSettings($hub->fresh());
        }

        $hub = $hub->fresh();

        return [
            'statuses' => $this->editableStatuses($hub),
            'compliance_status_labels' => $this->labels($hub),
        ];
    }
}
