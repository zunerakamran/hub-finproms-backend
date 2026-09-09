<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hub extends Model
{
    public const TYPE_SHARED = 'shared';

    public const TYPE_WHITE_LABEL = 'white_label';

    /**
     * Mutually exclusive checklist pairs.
     * Checking one automatically unchecks its opposite.
     *
     * @var array<string, string>
     */
    public const CHECKLIST_OPPOSITES = [
        'public_subscribe' => 'private_invite_only',
        'private_invite_only' => 'public_subscribe',
        'paid_credits' => 'unlimited_credits',
        'unlimited_credits' => 'paid_credits',
    ];

    /**
     * Known checklist keys and human labels for Power Admin UI.
     * Values are booleans unless noted.
     *
     * @var array<string, array{label: string, description: string, default_shared: bool, default_white_label: bool}>
     */
    public const CHECKLIST_DEFINITIONS = [
        'public_subscribe' => [
            'label' => 'Public subscribe / self-registration',
            'description' => 'Anyone can register and subscribe.',
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'private_invite_only' => [
            'label' => 'Private invite-only access',
            'description' => 'Only invited advisors (e.g. Excel import) can access.',
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'paid_credits' => [
            'label' => 'Paid credits',
            'description' => 'Users buy/earn credits via subscription or packs.',
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'unlimited_credits' => [
            'label' => 'Unlimited credits',
            'description' => 'Advisors have unlimited credits to buy content.',
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'one_off_purchase' => [
            'label' => 'One-off purchase (non-subscribers)',
            'description' => 'Non-subscribers can buy individual posts/reels (1 credit = £1).',
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'advisor_excel_import' => [
            'label' => 'Advisor Excel import',
            'description' => 'Client can import advisors from an Excel sheet.',
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'receive_content_from_shared' => [
            'label' => 'Receive content from shared hub',
            'description' => 'Shared hub can push/select posts onto this hub.',
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'ai_content_generation' => [
            'label' => 'AI content generation',
            'description' => 'Generate posts/reels with AI (future).',
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'in_app_post_editing' => [
            'label' => 'In-app post editing',
            'description' => 'Buyers can edit purchased posts in-app (future).',
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'compliance_check' => [
            'label' => 'Compliance check',
            'description' => 'Posts can go through a compliance flow (future).',
            'default_shared' => false,
            'default_white_label' => false,
        ],
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_active',
        'primary_color',
        'secondary_color',
        'logo_url',
        'checklist',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'checklist' => 'array',
        ];
    }

    public function isShared(): bool
    {
        return $this->type === self::TYPE_SHARED;
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultChecklist(string $type = self::TYPE_WHITE_LABEL): array
    {
        $key = $type === self::TYPE_SHARED ? 'default_shared' : 'default_white_label';
        $defaults = [];

        foreach (self::CHECKLIST_DEFINITIONS as $flag => $meta) {
            $defaults[$flag] = (bool) $meta[$key];
        }

        return $defaults;
    }

    /**
     * Merged checklist (stored overrides + defaults for missing keys).
     *
     * @return array<string, bool>
     */
    public function resolvedChecklist(): array
    {
        $defaults = self::defaultChecklist($this->type);
        $stored = is_array($this->checklist) ? $this->checklist : [];

        $resolved = $defaults;
        foreach (array_keys(self::CHECKLIST_DEFINITIONS) as $flag) {
            if (array_key_exists($flag, $stored)) {
                $resolved[$flag] = filter_var($stored[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $resolved;
    }

    public function can(string $flag): bool
    {
        $checklist = $this->resolvedChecklist();

        return (bool) ($checklist[$flag] ?? false);
    }

    /**
     * Checklist payload for Power Admin UI (flags + labels + current values).
     *
     * @return list<array{key: string, label: string, description: string, enabled: bool}>
     */
    public function checklistForAdmin(): array
    {
        $resolved = $this->resolvedChecklist();
        $items = [];

        foreach (self::CHECKLIST_DEFINITIONS as $key => $meta) {
            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => (bool) ($resolved[$key] ?? false),
                'exclusive_with' => self::CHECKLIST_OPPOSITES[$key] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'branding' => [
                'primary_color' => $this->primary_color,
                'secondary_color' => $this->secondary_color,
                'logo_url' => $this->logo_url,
            ],
            'checklist' => $this->resolvedChecklist(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'branding' => [
                'primary_color' => $this->primary_color,
                'secondary_color' => $this->secondary_color,
                'logo_url' => $this->logo_url,
            ],
            'checklist' => $this->checklistForAdmin(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
