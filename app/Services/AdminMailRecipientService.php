<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;

/**
 * Resolves recipients for all hub admin notification emails
 * from the single "Receive admin emails" capability.
 */
class AdminMailRecipientService
{
    public const CAPABILITY = 'receive_admin_emails';

    public function __construct(
        private readonly CapabilitiesMatrixService $capabilities
    ) {}

    /**
     * Email addresses for users whose role has the admin-emails capability on this hub.
     *
     * @return list<string>
     */
    public function emailsForHub(Hub $hub): array
    {
        $roles = array_values(array_filter(
            CapabilitiesMatrixService::MATRIX_ROLES,
            fn (string $role) => $this->capabilities->roleCan($hub, $role, self::CAPABILITY)
        ));

        if ($roles === []) {
            return [];
        }

        return User::query()
            ->whereIn('role', $roles)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
