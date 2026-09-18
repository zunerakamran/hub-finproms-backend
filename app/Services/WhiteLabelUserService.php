<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Read/write users on a white-label hub's own database while the shared
 * dashboard hub switcher is acting on that hub.
 */
class WhiteLabelUserService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function assertTarget(Hub $hub): void
    {
        if ($hub->isShared()) {
            throw new InvalidArgumentException('Select a white-label hub, not the shared hub.');
        }
        if (! $hub->is_active) {
            throw new InvalidArgumentException('That white-label hub is inactive.');
        }
        $this->remoteDb->assertConfigured($hub);
    }

    /**
     * @return array{users: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(Hub $hub, ?string $search, ?string $role, int $perPage, int $page = 1): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $search, $role, $perPage, $page) {
            $query = User::on($connection)->orderBy('name')->orderBy('id');

            if ($search) {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            }

            if ($role) {
                $query->where('role', $role);
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return [
                'users' => $paginator->getCollection()
                    ->map(fn (User $user) => $this->serialize($user, $hub))
                    ->values()
                    ->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(Hub $hub, array $payload): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $payload) {
            $this->assertEmailAvailable($connection, (string) $payload['email']);

            $user = User::on($connection)->create([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role' => $payload['role'],
                'credits' => $payload['credits'] ?? 0,
                'is_advisor' => $payload['is_advisor'] ?? ($payload['role'] === User::ROLE_ADVISOR),
                'has_unlimited_credits' => $payload['has_unlimited_credits'] ?? false,
                'is_suspended' => $payload['is_suspended'] ?? false,
            ]);

            return $this->serialize($user->fresh(), $hub);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function find(Hub $hub, int $userId): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $userId) {
            return $this->serialize($this->findOrFail($connection, $userId), $hub);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{user: array<string, mixed>, password_changed: bool, newly_suspended: bool}
     */
    public function update(Hub $hub, int $userId, array $payload): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $userId, $payload) {
            $user = $this->findOrFail($connection, $userId);

            if (array_key_exists('email', $payload)) {
                $this->assertEmailAvailable($connection, (string) $payload['email'], $user->id);
            }

            $wasSuspended = (bool) $user->is_suspended;
            $fill = collect($payload)->except(['password'])->all();

            if (! empty($payload['password'])) {
                $fill['password'] = $payload['password'];
            }

            if (($fill['role'] ?? null) === User::ROLE_ADVISOR && ! array_key_exists('is_advisor', $fill)) {
                $fill['is_advisor'] = true;
            }

            $user->fill($fill);
            $user->save();

            $passwordChanged = array_key_exists('password', $fill);
            if ($passwordChanged) {
                $this->deleteTokens($connection, $user->id);
            }

            $newlySuspended = array_key_exists('is_suspended', $fill)
                && (bool) $fill['is_suspended'] === true
                && ! $wasSuspended;

            if ($newlySuspended) {
                $this->deleteTokens($connection, $user->id);
            }

            return [
                'user' => $this->serialize($user->fresh(), $hub),
                'password_changed' => $passwordChanged,
                'newly_suspended' => $newlySuspended,
            ];
        });
    }

    public function delete(Hub $hub, int $userId): void
    {
        $this->assertTarget($hub);

        $this->remoteDb->run($hub, function (string $connection) use ($userId): void {
            $user = $this->findOrFail($connection, $userId);

            if ($user->role === User::ROLE_POWER_ADMIN && $this->powerAdminCountOnConnection($connection) <= 1) {
                throw ValidationException::withMessages([
                    'user' => 'Cannot delete the last Power Admin account on this hub.',
                ]);
            }

            $this->deleteTokens($connection, $user->id);
            $user->delete();
        });
    }

    public function powerAdminCount(Hub $hub): int
    {
        $this->assertTarget($hub);

        return (int) $this->remoteDb->run($hub, function (string $connection) {
            return $this->powerAdminCountOnConnection($connection);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user, ?Hub $hub = null): array
    {
        $hubAllowsUnlimited = $hub ? $hub->givesUnlimitedSubscriberCredits() : false;
        $unlimited = $user->hasUnlimitedCredits($hubAllowsUnlimited);
        $roleLabel = $hub
            ? $hub->roleLabel((string) $user->role)
            : ($user->role_label ?? (User::ROLE_LABELS[$user->role] ?? (string) $user->role));

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $roleLabel,
            'credits' => $unlimited ? 0 : (int) $user->credits,
            'is_advisor' => (bool) $user->is_advisor,
            'has_unlimited_credits' => $unlimited,
            'is_suspended' => (bool) $user->is_suspended,
            'is_discontinued' => (bool) $user->is_discontinued,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    private function findOrFail(string $connection, int $userId): User
    {
        $user = User::on($connection)->find($userId);
        if (! $user) {
            throw new InvalidArgumentException('User not found on this white-label hub.');
        }

        return $user;
    }

    private function assertEmailAvailable(string $connection, string $email, ?int $ignoreId = null): void
    {
        $query = User::on($connection)->where('email', $email);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken on this hub.',
            ]);
        }
    }

    private function deleteTokens(string $connection, int $userId): void
    {
        if (! DB::connection($connection)->getSchemaBuilder()->hasTable('personal_access_tokens')) {
            return;
        }

        DB::connection($connection)->table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();
    }

    private function powerAdminCountOnConnection(string $connection): int
    {
        return User::on($connection)->where('role', User::ROLE_POWER_ADMIN)->count();
    }
}
