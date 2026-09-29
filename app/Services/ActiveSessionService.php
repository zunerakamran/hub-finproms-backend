<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;

class ActiveSessionService
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly ActivityLogService $activityLogs,
    ) {}

    /**
     * Hub whose users/sessions are being managed (acting content hub when switcher is on).
     */
    public function targetHub(?User $actor): Hub
    {
        if ($actor && $this->actingHubs->isActingRemotely($actor)) {
            return $this->actingHubs->requireActingContentHub($actor);
        }

        return $this->actingHubs->targetHub($actor);
    }

    /**
     * @return array{
     *   hub: array{id: int, name: string, slug: string},
     *   data: list<array<string, mixed>>,
     *   meta: array{total_users: int, total_sessions: int}
     * }
     */
    public function listForActor(User $actor, ?int $currentTokenId = null): array
    {
        $hub = $this->targetHub($actor);

        if ($hub->isContentHub() && $this->actingHubs->isActingRemotely($actor)) {
            $this->remoteDb->assertConfigured($hub);

            return $this->remoteDb->run($hub, function (string $connection) use ($hub, $actor) {
                // Current token lives on the control-plane/local DB — never "current" on remote.
                return $this->listOnConnection($connection, $hub, $actor, null);
            });
        }

        return $this->listOnConnection(
            (string) config('database.default'),
            $hub,
            $actor,
            $currentTokenId
        );
    }

    /**
     * Force-logout a user by deleting all of their Sanctum tokens on the target hub DB.
     *
     * @return array{message: string, user_id: int, tokens_revoked: int, logged_out_self: bool}
     */
    public function forceLogout(User $actor, int $userId, ?int $currentTokenId = null): array
    {
        $hub = $this->targetHub($actor);

        if ($hub->isContentHub() && $this->actingHubs->isActingRemotely($actor)) {
            $this->remoteDb->assertConfigured($hub);

            return $this->remoteDb->run($hub, function (string $connection) use ($hub, $actor, $userId) {
                return $this->forceLogoutOnConnection($connection, $hub, $actor, $userId, null);
            });
        }

        return $this->forceLogoutOnConnection(
            (string) config('database.default'),
            $hub,
            $actor,
            $userId,
            $currentTokenId
        );
    }

    /**
     * @return array{
     *   hub: array{id: int, name: string, slug: string},
     *   data: list<array<string, mixed>>,
     *   meta: array{total_users: int, total_sessions: int}
     * }
     */
    private function listOnConnection(
        string $connection,
        Hub $hub,
        User $actor,
        ?int $currentTokenId
    ): array {
        if (! DB::connection($connection)->getSchemaBuilder()->hasTable('personal_access_tokens')) {
            return [
                'hub' => $this->hubPayload($hub),
                'data' => [],
                'meta' => ['total_users' => 0, 'total_sessions' => 0],
            ];
        }

        $tokens = DB::connection($connection)->table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();

        if ($tokens->isEmpty()) {
            return [
                'hub' => $this->hubPayload($hub),
                'data' => [],
                'meta' => ['total_users' => 0, 'total_sessions' => 0],
            ];
        }

        $userIds = $tokens->pluck('tokenable_id')->unique()->values()->all();
        $users = User::on($connection)
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'role'])
            ->keyBy('id');

        $grouped = $tokens->groupBy('tokenable_id');
        $rows = [];

        foreach ($grouped as $userId => $userTokens) {
            $user = $users->get((int) $userId);
            if (! $user) {
                continue;
            }

            $sessions = $this->serializeSessions($userTokens, $currentTokenId);
            $lastActivity = collect($sessions)
                ->map(fn (array $s) => $s['last_used_at'] ?? $s['created_at'])
                ->filter()
                ->sortDesc()
                ->first();

            $actingOnRemote = $hub->isContentHub()
                && $this->actingHubs->isActingRemotely($actor);
            $isCurrentUser = ! $actingOnRemote
                && (int) $user->id === (int) $actor->id;

            $rows[] = [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'session_count' => count($sessions),
                'sessions' => $sessions,
                'last_activity_at' => $lastActivity,
                'is_current_user' => $isCurrentUser,
            ];
        }

        usort($rows, function (array $a, array $b) {
            return strcmp((string) ($b['last_activity_at'] ?? ''), (string) ($a['last_activity_at'] ?? ''));
        });

        return [
            'hub' => $this->hubPayload($hub),
            'data' => $rows,
            'meta' => [
                'total_users' => count($rows),
                'total_sessions' => $tokens->count(),
            ],
        ];
    }

    /**
     * @return array{message: string, user_id: int, tokens_revoked: int, logged_out_self: bool}
     */
    private function forceLogoutOnConnection(
        string $connection,
        Hub $hub,
        User $actor,
        int $userId,
        ?int $currentTokenId
    ): array {
        if (! DB::connection($connection)->getSchemaBuilder()->hasTable('personal_access_tokens')) {
            throw new InvalidArgumentException('Session table is not available on this hub.');
        }

        $user = User::on($connection)->find($userId);
        if (! $user) {
            throw new InvalidArgumentException('User not found on this hub.');
        }

        $query = DB::connection($connection)->table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId);

        $tokensRevoked = (int) $query->count();
        $query->delete();

        $loggedOutSelf = $currentTokenId !== null
            && (int) $user->id === (int) $actor->id
            && ! ($hub->isContentHub() && $this->actingHubs->isActingRemotely($actor));

        try {
            $this->activityLogs->log([
                'action' => 'auth.force_logout',
                'description' => 'Force-logged out user: '.$user->email.' ('.$tokensRevoked.' session(s))',
                'user' => $actor,
                'subject' => $user,
                'status_code' => 200,
                'properties' => [
                    'target_user_id' => (int) $user->id,
                    'target_email' => $user->email,
                    'tokens_revoked' => $tokensRevoked,
                    'hub_id' => $hub->id,
                    'hub_slug' => $hub->slug,
                ],
            ]);
        } catch (\Throwable) {
            //
        }

        return [
            'message' => $tokensRevoked > 0
                ? 'User has been logged out ('.$tokensRevoked.' session(s) ended).'
                : 'User had no active sessions.',
            'user_id' => (int) $user->id,
            'tokens_revoked' => $tokensRevoked,
            'logged_out_self' => $loggedOutSelf,
        ];
    }

    /**
     * @param  Collection<int, object>  $tokens
     * @return list<array<string, mixed>>
     */
    private function serializeSessions(Collection $tokens, ?int $currentTokenId): array
    {
        return $tokens->map(function ($token) use ($currentTokenId) {
            $id = (int) $token->id;

            return [
                'id' => $id,
                'name' => (string) ($token->name ?? 'auth_token'),
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'is_current' => $currentTokenId !== null && $id === $currentTokenId,
            ];
        })->values()->all();
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => (int) $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
        ];
    }

    public function currentTokenId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        $token = $user->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            return (int) $token->id;
        }

        return null;
    }
}
