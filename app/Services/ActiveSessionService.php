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
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
        private readonly ActivityLogService $activityLogs,
        private readonly SecurityMonitoringService $securityMonitoring,
        private readonly HubService $hubs,
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

        // Also drop httpOnly cookie sessions so force-logout ends SPA cookie auth.
        $sessionsRevoked = 0;
        if (DB::connection($connection)->getSchemaBuilder()->hasTable('sessions')
            && DB::connection($connection)->getSchemaBuilder()->hasColumn('sessions', 'user_id')
        ) {
            $sessionsRevoked = (int) DB::connection($connection)
                ->table('sessions')
                ->where('user_id', $userId)
                ->delete();
        }

        $ended = $tokensRevoked + $sessionsRevoked;

        $loggedOutSelf = $currentTokenId !== null
            && (int) $user->id === (int) $actor->id
            && ! ($hub->isContentHub() && $this->actingHubs->isActingRemotely($actor));

        try {
            $this->activityLogs->log([
                'action' => 'auth.force_logout',
                'description' => 'Force-logged out user: '.$user->email
                    .' ('.$tokensRevoked.' token(s), '.$sessionsRevoked.' cookie session(s))',
                'user' => $actor,
                'subject' => $user,
                'status_code' => 200,
                'properties' => [
                    'target_user_id' => (int) $user->id,
                    'target_email' => $user->email,
                    'tokens_revoked' => $tokensRevoked,
                    'sessions_revoked' => $sessionsRevoked,
                    'hub_id' => $hub->id,
                    'hub_slug' => $hub->slug,
                ],
            ]);
        } catch (\Throwable) {
            //
        }

        try {
            $this->securityMonitoring->recordForceLogout($hub, $actor, $user, [
                'tokens_revoked' => $tokensRevoked,
                'sessions_revoked' => $sessionsRevoked,
            ]);
        } catch (\Throwable) {
            //
        }

        return [
            'message' => $ended > 0
                ? 'User has been logged out ('.$ended.' session(s) ended).'
                : 'User had no active sessions.',
            'user_id' => (int) $user->id,
            'tokens_revoked' => $tokensRevoked,
            'sessions_revoked' => $sessionsRevoked,
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

    /**
     * Session idle timeout settings for the target hub.
     *
     * @return array{
     *   hub: array{id: int, name: string, slug: string},
     *   data: array{
     *     session_lifetime_minutes: int,
     *     default_minutes: int,
     *     min_minutes: int,
     *     max_minutes: int,
     *     is_custom: bool
     *   }
     * }
     */
    public function sessionSettingsForActor(User $actor): array
    {
        $hub = $this->targetHub($actor);

        return [
            'hub' => $this->hubPayload($hub),
            'data' => $this->sessionSettingsPayload($hub),
        ];
    }

    /**
     * Update idle session timeout for the target hub (minutes).
     * Immediately ends all active sessions on that hub so the new timeout
     * applies from the next login. Pushes to the content hub’s own DB when
     * Central has remote wiring.
     *
     * @return array{
     *   message: string,
     *   hub: array{id: int, name: string, slug: string},
     *   data: array{
     *     session_lifetime_minutes: int,
     *     default_minutes: int,
     *     min_minutes: int,
     *     max_minutes: int,
     *     is_custom: bool
     *   },
     *   tokens_revoked: int,
     *   sessions_revoked: int,
     *   logged_out_self: bool
     * }
     */
    public function updateSessionSettings(User $actor, int $minutes): array
    {
        $hub = $this->targetHub($actor);
        $minutes = max(
            Hub::SESSION_LIFETIME_MIN_MINUTES,
            min(Hub::SESSION_LIFETIME_MAX_MINUTES, $minutes)
        );

        $previous = $hub->session_lifetime_minutes;
        $hub->session_lifetime_minutes = $minutes;
        $hub->save();
        $this->hubs->forgetCurrentCache();

        if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
            $this->whiteLabelSync->pushSettings($hub->fresh() ?? $hub);
        }

        $revocation = $this->revokeAllSessionsForHub($actor, $hub);

        try {
            $this->activityLogs->log([
                'action' => 'auth.session_timeout_updated',
                'description' => 'Session idle timeout set to '.$minutes.' minute(s) on hub '.$hub->slug
                    .' (ended '.$revocation['tokens_revoked'].' token(s), '
                    .$revocation['sessions_revoked'].' cookie session(s))',
                'user' => $actor,
                'subject' => $hub,
                'status_code' => 200,
                'properties' => [
                    'hub_id' => $hub->id,
                    'hub_slug' => $hub->slug,
                    'previous_minutes' => $previous,
                    'session_lifetime_minutes' => $minutes,
                    'tokens_revoked' => $revocation['tokens_revoked'],
                    'sessions_revoked' => $revocation['sessions_revoked'],
                ],
            ]);
        } catch (\Throwable) {
            //
        }

        $hub = $hub->fresh() ?? $hub;
        $duration = $this->formatDuration($minutes);

        return [
            'message' => 'Session timeout updated to '.$duration
                .'. All active users on this hub have been logged out.',
            'hub' => $this->hubPayload($hub),
            'data' => $this->sessionSettingsPayload($hub),
            'tokens_revoked' => $revocation['tokens_revoked'],
            'sessions_revoked' => $revocation['sessions_revoked'],
            'logged_out_self' => $revocation['logged_out_self'],
        ];
    }

    /**
     * End every Sanctum token + cookie session on the hub where auth lives.
     *
     * @return array{tokens_revoked: int, sessions_revoked: int, logged_out_self: bool}
     */
    private function revokeAllSessionsForHub(User $actor, Hub $hub): array
    {
        $actingRemotely = $hub->isContentHub() && $this->actingHubs->isActingRemotely($actor);

        if ($actingRemotely) {
            $this->remoteDb->assertConfigured($hub);

            return $this->remoteDb->run($hub, function (string $connection) {
                return $this->revokeAllSessionsOnConnection($connection, loggedOutSelf: false);
            });
        }

        return $this->revokeAllSessionsOnConnection(
            (string) config('database.default'),
            loggedOutSelf: true
        );
    }

    /**
     * @return array{tokens_revoked: int, sessions_revoked: int, logged_out_self: bool}
     */
    private function revokeAllSessionsOnConnection(string $connection, bool $loggedOutSelf): array
    {
        $tokensRevoked = 0;
        $sessionsRevoked = 0;
        $schema = DB::connection($connection)->getSchemaBuilder();

        if ($schema->hasTable('personal_access_tokens')) {
            $query = DB::connection($connection)->table('personal_access_tokens')
                ->where('tokenable_type', User::class);
            $tokensRevoked = (int) $query->count();
            $query->delete();
        }

        if ($schema->hasTable('sessions') && $schema->hasColumn('sessions', 'user_id')) {
            $query = DB::connection($connection)->table('sessions')
                ->whereNotNull('user_id');
            $sessionsRevoked = (int) $query->count();
            $query->delete();
        }

        return [
            'tokens_revoked' => $tokensRevoked,
            'sessions_revoked' => $sessionsRevoked,
            'logged_out_self' => $loggedOutSelf,
        ];
    }

    /**
     * @return array{
     *   session_lifetime_minutes: int,
     *   default_minutes: int,
     *   min_minutes: int,
     *   max_minutes: int,
     *   is_custom: bool
     * }
     */
    private function sessionSettingsPayload(Hub $hub): array
    {
        $default = max(1, (int) config('session.lifetime', 10080));

        return [
            'session_lifetime_minutes' => $hub->resolvedSessionLifetimeMinutes(),
            'default_minutes' => $default,
            'min_minutes' => Hub::SESSION_LIFETIME_MIN_MINUTES,
            'max_minutes' => Hub::SESSION_LIFETIME_MAX_MINUTES,
            'is_custom' => $hub->session_lifetime_minutes !== null
                && (int) $hub->session_lifetime_minutes > 0,
        ];
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);

            return $days.' day'.($days === 1 ? '' : 's');
        }
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.' hour'.($hours === 1 ? '' : 's');
        }

        return $minutes.' minute'.($minutes === 1 ? '' : 's');
    }
}
