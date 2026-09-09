<?php

namespace App\Services;

use App\Models\Setting;

class PowerAdminCapabilitiesService
{
    public const SETTING_KEY = 'power_admin_capabilities';

    /**
     * @var array<string, array{label: string, description: string, default: bool}>
     */
    public const DEFINITIONS = [
        'pa_view_dashboard' => [
            'label' => 'View platform dashboard',
            'description' => 'Access the Power Admin home dashboard.',
            'default' => true,
        ],
        'pa_manage_hubs' => [
            'label' => 'Manage hubs',
            'description' => 'Create and edit shared / white-labelled hubs and branding.',
            'default' => true,
        ],
        'pa_manage_hub_checklists' => [
            'label' => 'Manage hub checklists',
            'description' => 'Edit per-hub Functionalities (access, credits, distribution).',
            'default' => true,
        ],
        'pa_manage_payment_methods' => [
            'label' => 'Manage payment methods',
            'description' => 'Enable/disable Stripe and bank transfer for the platform.',
            'default' => true,
        ],
        'pa_manage_power_capabilities' => [
            'label' => 'Manage capabilities matrix',
            'description' => 'Edit user capabilities by role (member, hub-admin, Power Admin).',
            'default' => true,
        ],
        'pa_manage_users_roles' => [
            'label' => 'Manage users & roles',
            'description' => 'Assign roles (FinProms admin, client admin, manager, approver, advisor, etc.) — future.',
            'default' => false,
        ],
    ];

    /**
     * @return array<string, bool>
     */
    public function resolved(): array
    {
        $stored = $this->storedMap();
        $resolved = [];

        foreach (self::DEFINITIONS as $key => $meta) {
            $resolved[$key] = array_key_exists($key, $stored)
                ? filter_var($stored[$key], FILTER_VALIDATE_BOOLEAN)
                : (bool) $meta['default'];
        }

        return $resolved;
    }

    public function can(string $flag): bool
    {
        $resolved = $this->resolved();

        return (bool) ($resolved[$flag] ?? false);
    }

    /**
     * @return list<array{key: string, label: string, description: string, enabled: bool}>
     */
    public function forAdmin(): array
    {
        $resolved = $this->resolved();
        $items = [];

        foreach (self::DEFINITIONS as $key => $meta) {
            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => (bool) ($resolved[$key] ?? false),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $partial
     * @return list<array{key: string, label: string, description: string, enabled: bool}>
     */
    public function update(array $partial): array
    {
        $current = $this->resolved();

        foreach (array_keys(self::DEFINITIONS) as $key) {
            if (array_key_exists($key, $partial)) {
                $current[$key] = filter_var($partial[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        // Never lock everyone out of editing this list.
        $current['pa_manage_power_capabilities'] = true;

        Setting::setValue(self::SETTING_KEY, json_encode($current));

        return $this->forAdmin();
    }

    public function seedDefaults(): void
    {
        if (Setting::query()->where('key', self::SETTING_KEY)->exists()) {
            return;
        }

        $defaults = [];
        foreach (self::DEFINITIONS as $key => $meta) {
            $defaults[$key] = (bool) $meta['default'];
        }

        Setting::setValue(self::SETTING_KEY, json_encode($defaults));
    }

    /**
     * @return array<string, mixed>
     */
    private function storedMap(): array
    {
        $raw = Setting::getValue(self::SETTING_KEY, null);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
