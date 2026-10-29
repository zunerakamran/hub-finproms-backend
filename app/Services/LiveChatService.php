<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\LiveChatConversation;
use App\Models\LiveChatMessage;
use App\Models\User;
use App\Support\HubInternalAuth;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class LiveChatService
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly ActivityLogService $activity,
    ) {}

    public function assertModuleEnabled(Hub $hub): void
    {
        if (! $hub->hasLiveChatFunctionality()) {
            throw ValidationException::withMessages([
                'live_chat' => 'Live Chat is not enabled for this hub.',
            ]);
        }
    }

    public function assertCanUse(Hub $hub, User $user): void
    {
        // Central inbox: unlocked by content-hub Central contacts (not a Central matrix tick).
        if ($this->isCentralAgentContext($hub, $user)) {
            if (! $this->centralUserMayUseChat($user)) {
                throw ValidationException::withMessages([
                    'capability' => 'You are not listed as a Live Chat Central contact on any Shared or White-label hub.',
                ]);
            }

            return;
        }

        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        if (! $this->matrix->roleCan($hub, $role, 'lc_use_chat')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to use Live Chat.',
            ]);
        }
    }

    /**
     * Whether a Central Hub user may open Live Chat / reply.
     * True when Power Admin, or when any content hub with Live Chat enabled
     * lists this user in hubs.live_chat_agents (Central contacts page).
     */
    public function centralUserMayUseChat(User $user): bool
    {
        if ($user->isPowerAdmin()) {
            return true;
        }

        return $this->isListedAsCentralAgent($user);
    }

    /**
     * True when this Central user id appears in live_chat_agents on a content
     * hub that has Functionalities → Live Chat enabled.
     */
    public function isListedAsCentralAgent(User $user): bool
    {
        $id = (int) $user->id;
        if ($id < 1) {
            return false;
        }

        $hubs = Hub::query()
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->get(['id', 'type', 'checklist', 'live_chat_agents']);

        foreach ($hubs as $hub) {
            if (! $hub->hasLiveChatFunctionality()) {
                continue;
            }
            $agents = is_array($hub->live_chat_agents) ? $hub->live_chat_agents : [];
            foreach ($agents as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if ((int) ($row['id'] ?? 0) === $id) {
                    return true;
                }
            }
        }

        return false;
    }

    public function assertCanManageAccess(Hub $hub, User $user): void
    {
        // Contact picker lives on Shared / White-label hubs only (not Central).
        if ($hub->isCentral() || ! $hub->isContentHub()) {
            throw ValidationException::withMessages([
                'live_chat' => 'Choose Central contacts on each Shared or White-label hub — not on Central Hub.',
            ]);
        }

        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        $can = $this->matrix->roleCan($hub, $role, 'lc_manage_access')
            || $user->isPowerAdmin();
        if (! $can) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to choose Live Chat Central contacts.',
            ]);
        }
    }

    /**
     * Whether this request is from a Central Hub agent (not a content-hub member).
     * Content hubs (and Central while acting remotely on them) use the hub-user side.
     */
    public function isCentralAgentContext(Hub $hub, User $user): bool
    {
        if ($hub->isContentHub()) {
            return false;
        }

        return $hub->isCentral() || (bool) config('hub.is_control_plane', false);
    }

    /**
     * Central contacts this content hub may message (from hubs.live_chat_agents).
     *
     * @return list<array{id:int,name:string,email:string}>
     */
    public function availableAgents(Hub $hub): array
    {
        if (! $hub->isContentHub()) {
            return [];
        }

        // Prefer the content hub DB copy when already remounted (hub switcher),
        // else the Central registry column on $hub.
        $agents = $this->readAgentsFromActingContext($hub);
        if ($agents === null) {
            $agents = is_array($hub->live_chat_agents) ? $hub->live_chat_agents : [];
        }

        $out = [];
        foreach ($agents as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? 'Central user'),
                'email' => (string) ($row['email'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * When acting_wl_wc_db has remounted onto the content hub, read
     * live_chat_agents from that hubs row (avoids nested remote DB connect).
     *
     * @return list<array<string, mixed>>|null
     */
    private function readAgentsFromActingContext(Hub $hub): ?array
    {
        if (! WcDatabaseContext::active()) {
            return null;
        }
        if (WcDatabaseContext::hubId() !== null && (int) WcDatabaseContext::hubId() !== (int) $hub->id) {
            return null;
        }

        $connection = WcDatabaseContext::connection();
        if (! is_string($connection) || $connection === '') {
            return null;
        }

        try {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('hubs') || ! $schema->hasColumn('hubs', 'live_chat_agents')) {
                return null;
            }

            $row = DB::connection($connection)->table('hubs')
                ->where('slug', $hub->slug)
                ->value('live_chat_agents');

            if ($row === null && $schema->hasColumn('hubs', 'type')) {
                $row = DB::connection($connection)->table('hubs')
                    ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
                    ->value('live_chat_agents');
            }

            if ($row === null || $row === '') {
                return [];
            }
            if (is_array($row)) {
                return $row;
            }
            $decoded = json_decode((string) $row, true);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Checkbox list for Shared / White-label: all Central Hub users, with
     * which ones are selected as contacts for this hub.
     *
     * @return list<array{id:int,name:string,email:string,role:?string,role_label:?string,selected:bool}>
     */
    public function accessPickerUsers(Hub $hub): array
    {
        $directory = $this->listCentralDirectoryUsers($hub);
        $selectedIds = collect($this->availableAgents($hub))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return collect($directory)
            ->map(function (array $row) use ($selectedIds) {
                $id = (int) ($row['id'] ?? 0);

                return [
                    'id' => $id,
                    'name' => (string) ($row['name'] ?? ''),
                    'email' => (string) ($row['email'] ?? ''),
                    'role' => $row['role'] ?? null,
                    'role_label' => $row['role_label'] ?? null,
                    'selected' => in_array($id, $selectedIds, true),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Save which Central users this content hub may contact.
     *
     * @param  list<int>  $enabledUserIds
     * @return list<array{id:int,name:string,email:string}>
     */
    public function saveAccess(Hub $hub, User $actor, array $enabledUserIds): array
    {
        $this->assertCanManageAccess($hub, $actor);

        $ids = array_values(array_unique(array_map('intval', $enabledUserIds)));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));
        $idSet = array_fill_keys($ids, true);

        $directory = $this->listCentralDirectoryUsers($hub);
        $agents = [];
        foreach ($directory as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || ! isset($idSet[$id])) {
                continue;
            }
            $agents[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? 'Central user'),
                'email' => (string) ($row['email'] ?? ''),
            ];
        }

        if (Schema::hasColumn('hubs', 'live_chat_agents')) {
            $hub->live_chat_agents = $agents;
            $hub->save();
        }

        // When Power Admin saves from Central while acting on this hub, also
        // push the agent list onto the content hub database.
        if (config('hub.is_control_plane') && $hub->hasRemoteDatabaseConfigured()) {
            $this->pushAgentsToRemoteHub($hub, $agents);
        }

        $this->activity->log([
            'action' => 'live_chat.access_updated',
            'description' => 'Updated Live Chat Central contacts ('.count($agents).' selected)',
            'user' => $actor,
            'hub' => $hub,
            'status_code' => 200,
            'properties' => [
                'enabled_user_ids' => $ids,
                'agent_count' => count($agents),
            ],
        ]);

        return $agents;
    }

    /**
     * Full Central user directory for the contact picker.
     *
     * @return list<array{id:int,name:string,email:string,role:?string,role_label:?string}>
     */
    public function listCentralDirectoryUsers(Hub $hub): array
    {
        // Central deploy (including hub switcher acting on Shared/WL): local users.
        if (config('hub.is_control_plane')) {
            return $this->centralDirectoryFromLocalDb();
        }

        // Content hub deploy: ask Central over CENTRAL_API_URL.
        return $this->centralDirectoryFromCentralApi($hub);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConversations(Hub $hub, User $user): array
    {
        $this->assertCanUse($hub, $user);

        if ($this->isCentralAgentContext($hub, $user)) {
            return $this->listCentralAgentConversations($user);
        }

        return LiveChatConversation::query()
            ->where('hub_user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (LiveChatConversation $c) => $c->toApiArray())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function showConversation(Hub $hub, User $user, int $conversationId, ?string $sourceHubSlug = null): array
    {
        $this->assertCanUse($hub, $user);

        if ($this->isCentralAgentContext($hub, $user)) {
            return $this->showCentralAgentConversation($user, $conversationId, $sourceHubSlug);
        }

        $conversation = LiveChatConversation::query()
            ->where('hub_user_id', $user->id)
            ->whereKey($conversationId)
            ->firstOrFail();

        $this->markReadForHubUser($conversation);
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);

        return array_merge($conversation->toApiArray(includeMessages: true), [
            'source_hub_slug' => null,
            'source_hub_name' => null,
        ]);
    }

    /**
     * Hub user starts (or reuses) a conversation with a Central agent.
     *
     * @return array<string, mixed>
     */
    public function startConversation(Hub $hub, User $user, int $centralUserId, ?string $message = null): array
    {
        $this->assertCanUse($hub, $user);

        if ($this->isCentralAgentContext($hub, $user)) {
            throw ValidationException::withMessages([
                'live_chat' => 'Central agents cannot start chats with other Central agents from this screen. Wait for hub users to message you.',
            ]);
        }

        $agent = collect($this->availableAgents($hub))
            ->firstWhere('id', $centralUserId);
        if (! $agent) {
            throw ValidationException::withMessages([
                'central_user_id' => 'That Central Hub user is not available for Live Chat.',
            ]);
        }

        $conversation = LiveChatConversation::query()->firstOrCreate(
            [
                'hub_user_id' => $user->id,
                'central_user_id' => $centralUserId,
            ],
            [
                'hub_user_name' => $user->name,
                'hub_user_email' => $user->email,
                'central_user_name' => $agent['name'],
                'central_user_email' => $agent['email'],
                'status' => LiveChatConversation::STATUS_OPEN,
                'hub_unread_count' => 0,
                'central_unread_count' => 0,
            ]
        );

        // Refresh names in case profile changed.
        $conversation->hub_user_name = $user->name;
        $conversation->hub_user_email = $user->email;
        $conversation->central_user_name = $agent['name'];
        $conversation->central_user_email = $agent['email'];
        $conversation->status = LiveChatConversation::STATUS_OPEN;
        $conversation->save();

        if (is_string($message) && trim($message) !== '') {
            $this->postHubMessage($conversation, $user, trim($message));
        }

        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);

        $this->activity->log([
            'action' => 'live_chat.start',
            'description' => 'Started Live Chat conversation #'.$conversation->id,
            'user' => $user,
            'hub' => $hub,
            'subject' => $conversation,
            'status_code' => 201,
            'properties' => [
                'central_user_id' => $centralUserId,
            ],
        ]);

        return $conversation->toApiArray(includeMessages: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(
        Hub $hub,
        User $user,
        int $conversationId,
        string $body,
        ?string $sourceHubSlug = null
    ): array {
        $this->assertCanUse($hub, $user);
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Message cannot be empty.',
            ]);
        }
        if (mb_strlen($body) > 10000) {
            throw ValidationException::withMessages([
                'body' => 'Message is too long (max 10,000 characters).',
            ]);
        }

        if ($this->isCentralAgentContext($hub, $user)) {
            return $this->sendCentralAgentMessage($user, $conversationId, $body, $sourceHubSlug);
        }

        $conversation = LiveChatConversation::query()
            ->where('hub_user_id', $user->id)
            ->whereKey($conversationId)
            ->firstOrFail();

        $conversation->status = LiveChatConversation::STATUS_OPEN;
        $conversation->save();

        $message = $this->postHubMessage($conversation, $user, $body);
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')]);

        $this->activity->log([
            'action' => 'live_chat.message',
            'description' => 'Sent Live Chat message on conversation #'.$conversation->id,
            'user' => $user,
            'hub' => $hub,
            'subject' => $conversation,
            'status_code' => 201,
            'properties' => [
                'message_id' => $message->id,
                'side' => LiveChatMessage::SIDE_HUB,
            ],
        ]);

        return $conversation->toApiArray(includeMessages: true);
    }

    /**
     * Poll helper — unread totals for nav badges.
     *
     * @return array{unread:int,conversations:int}
     */
    public function unreadSummary(Hub $hub, User $user): array
    {
        try {
            $this->assertCanUse($hub, $user);
        } catch (Throwable) {
            return ['unread' => 0, 'conversations' => 0];
        }

        if ($this->isCentralAgentContext($hub, $user)) {
            $rows = $this->listCentralAgentConversations($user);
            $unread = collect($rows)->sum(fn ($r) => (int) ($r['central_unread_count'] ?? 0));

            return [
                'unread' => (int) $unread,
                'conversations' => count($rows),
            ];
        }

        $query = LiveChatConversation::query()->where('hub_user_id', $user->id);

        return [
            'unread' => (int) $query->sum('hub_unread_count'),
            'conversations' => (int) $query->count(),
        ];
    }

    private function postHubMessage(LiveChatConversation $conversation, User $user, string $body): LiveChatMessage
    {
        $message = LiveChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_side' => LiveChatMessage::SIDE_HUB,
            'sender_user_id' => $user->id,
            'sender_name' => $user->name,
            'body' => $body,
        ]);

        $conversation->last_message_at = now();
        $conversation->central_unread_count = (int) $conversation->central_unread_count + 1;
        $conversation->save();

        return $message;
    }

    private function markReadForHubUser(LiveChatConversation $conversation): void
    {
        LiveChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_side', LiveChatMessage::SIDE_CENTRAL)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if ((int) $conversation->hub_unread_count > 0) {
            $conversation->hub_unread_count = 0;
            $conversation->save();
        }
    }

    private function markReadForCentralAgent(string $connection, int $conversationId): void
    {
        DB::connection($connection)->table('live_chat_messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_side', LiveChatMessage::SIDE_HUB)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        DB::connection($connection)->table('live_chat_conversations')
            ->where('id', $conversationId)
            ->update([
                'central_unread_count' => 0,
                'updated_at' => now(),
            ]);
    }

    /**
     * Central Hub users from the control-plane database.
     *
     * IMPORTANT: while Power Admin uses the hub switcher, `acting_wl_wc_db`
     * remounts User onto the remote Shared/WL DB. We must clear that context
     * so this directory is always Central users — never the acting hub’s users.
     *
     * @return list<array{id:int,name:string,email:string,role:?string,role_label:?string}>
     */
    private function centralDirectoryFromLocalDb(): array
    {
        // Clear acting remote DB context so User does not remount onto Shared/WL.
        return WcDatabaseContext::using(null, function () {
            $connection = (string) config('database.default', 'mysql');

            $query = User::on($connection);
            if (Schema::connection($connection)->hasColumn('users', 'is_discontinued')) {
                $query->where(function ($q) {
                    $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
                });
            }

            return $query
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => (string) $u->name,
                    'email' => (string) $u->email,
                    'role' => $u->role,
                    'role_label' => $u->role_label ?? null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * @return list<array{id:int,name:string,email:string,role:?string,role_label:?string}>
     */
    private function centralDirectoryFromCentralApi(Hub $hub): array
    {
        $base = rtrim((string) config('services.hub_backup.central_api_url', ''), '/');
        if ($base === '') {
            throw ValidationException::withMessages([
                'live_chat' => 'CENTRAL_API_URL is not configured on this hub, so Central users cannot be listed. Ask Power Admin to complete deploy wiring.',
            ]);
        }

        $token = (string) ($hub->backup_token ?: config('services.hub_backup.secret', ''));
        if ($token === '') {
            throw ValidationException::withMessages([
                'live_chat' => 'This hub has no backup/identity token to call Central. Ask Power Admin to sync deploy wiring.',
            ]);
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    HubInternalAuth::HEADER_BACKUP => $token,
                    'X-Hub-Slug' => (string) $hub->slug,
                ])
                ->get($base.'/internal/live-chat/central-users', [
                    'hub_slug' => (string) $hub->slug,
                ]);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'live_chat' => 'Could not reach Central Hub to load users: '.$e->getMessage(),
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'live_chat' => 'Central Hub refused the Live Chat user directory request (HTTP '.$response->status().').',
            ]);
        }

        $rows = $response->json('data');
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => (string) ($row['name'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'role' => $row['role'] ?? null,
                'role_label' => $row['role_label'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{id:int,name:string,email:string}>  $agents
     */
    private function pushAgentsToRemoteHub(Hub $hub, array $agents): void
    {
        $payload = json_encode(array_values($agents));

        try {
            $this->remoteDb->run($hub, function (string $connection) use ($hub, $payload) {
                $schema = DB::connection($connection)->getSchemaBuilder();
                if (! $schema->hasTable('hubs') || ! $schema->hasColumn('hubs', 'live_chat_agents')) {
                    return;
                }

                DB::connection($connection)->table('hubs')
                    ->where(function ($q) use ($hub, $schema) {
                        $q->where('slug', $hub->slug);
                        if ($schema->hasColumn('hubs', 'type')) {
                            $q->orWhereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL]);
                        }
                    })
                    ->update([
                        'live_chat_agents' => $payload,
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listCentralAgentConversations(User $user): array
    {
        $rows = [];

        Hub::query()
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->where('is_active', true)
            ->orderBy('name')
            ->each(function (Hub $hub) use ($user, &$rows) {
                if (! $hub->hasLiveChatFunctionality() || ! $hub->hasRemoteDatabaseConfigured()) {
                    return;
                }

                try {
                    $remoteRows = $this->remoteDb->run($hub, function (string $connection) use ($hub, $user) {
                        $schema = DB::connection($connection)->getSchemaBuilder();
                        if (! $schema->hasTable('live_chat_conversations')) {
                            return [];
                        }

                        return DB::connection($connection)->table('live_chat_conversations')
                            ->where('central_user_id', $user->id)
                            ->orderByDesc('last_message_at')
                            ->orderByDesc('id')
                            ->get()
                            ->map(function ($row) use ($hub) {
                                return $this->mapRemoteConversation((array) $row, $hub);
                            })
                            ->all();
                    });

                    foreach ($remoteRows as $row) {
                        $rows[] = $row;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

        usort($rows, function ($a, $b) {
            $at = $a['last_message_at'] ?? '';
            $bt = $b['last_message_at'] ?? '';

            return strcmp((string) $bt, (string) $at);
        });

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function showCentralAgentConversation(User $user, int $conversationId, ?string $sourceHubSlug): array
    {
        $hub = $this->resolveSourceHub($sourceHubSlug);
        if (! $hub) {
            throw ValidationException::withMessages([
                'source_hub_slug' => 'source_hub_slug is required for Central Live Chat conversations.',
            ]);
        }

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $user, $conversationId) {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('live_chat_conversations')) {
                throw ValidationException::withMessages([
                    'conversation' => 'Live Chat is not available on that hub yet.',
                ]);
            }

            $row = DB::connection($connection)->table('live_chat_conversations')
                ->where('id', $conversationId)
                ->where('central_user_id', $user->id)
                ->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'conversation' => 'Conversation not found.',
                ]);
            }

            $this->markReadForCentralAgent($connection, $conversationId);

            $messages = DB::connection($connection)->table('live_chat_messages')
                ->where('conversation_id', $conversationId)
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => $this->mapRemoteMessage((array) $m))
                ->values()
                ->all();

            $fresh = DB::connection($connection)->table('live_chat_conversations')
                ->where('id', $conversationId)
                ->first();

            return array_merge(
                $this->mapRemoteConversation((array) $fresh, $hub),
                ['messages' => $messages]
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function sendCentralAgentMessage(
        User $user,
        int $conversationId,
        string $body,
        ?string $sourceHubSlug
    ): array {
        $hub = $this->resolveSourceHub($sourceHubSlug);
        if (! $hub) {
            throw ValidationException::withMessages([
                'source_hub_slug' => 'source_hub_slug is required for Central Live Chat conversations.',
            ]);
        }

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $user, $conversationId, $body) {
            $schema = DB::connection($connection)->getSchemaBuilder();
            if (! $schema->hasTable('live_chat_conversations')) {
                throw ValidationException::withMessages([
                    'conversation' => 'Live Chat is not available on that hub yet.',
                ]);
            }

            $row = DB::connection($connection)->table('live_chat_conversations')
                ->where('id', $conversationId)
                ->where('central_user_id', $user->id)
                ->first();

            if (! $row) {
                throw ValidationException::withMessages([
                    'conversation' => 'Conversation not found.',
                ]);
            }

            $now = now();
            $messageId = DB::connection($connection)->table('live_chat_messages')->insertGetId([
                'conversation_id' => $conversationId,
                'sender_side' => LiveChatMessage::SIDE_CENTRAL,
                'sender_user_id' => $user->id,
                'sender_name' => $user->name,
                'body' => $body,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::connection($connection)->table('live_chat_conversations')
                ->where('id', $conversationId)
                ->update([
                    'status' => LiveChatConversation::STATUS_OPEN,
                    'last_message_at' => $now,
                    'hub_unread_count' => (int) ($row->hub_unread_count ?? 0) + 1,
                    'central_user_name' => $user->name,
                    'central_user_email' => $user->email,
                    'updated_at' => $now,
                ]);

            $messages = DB::connection($connection)->table('live_chat_messages')
                ->where('conversation_id', $conversationId)
                ->orderBy('id')
                ->get()
                ->map(fn ($m) => $this->mapRemoteMessage((array) $m))
                ->values()
                ->all();

            $fresh = DB::connection($connection)->table('live_chat_conversations')
                ->where('id', $conversationId)
                ->first();

            $this->activity->log([
                'action' => 'live_chat.message',
                'description' => 'Sent Live Chat message on conversation #'.$conversationId,
                'user' => $user,
                'hub' => $hub,
                'status_code' => 201,
                'properties' => [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'side' => LiveChatMessage::SIDE_CENTRAL,
                    'source_hub_slug' => $hub->slug,
                ],
            ]);

            return array_merge(
                $this->mapRemoteConversation((array) $fresh, $hub),
                ['messages' => $messages]
            );
        });
    }

    private function resolveSourceHub(?string $slug): ?Hub
    {
        if (! is_string($slug) || trim($slug) === '') {
            return null;
        }

        return Hub::query()
            ->where('slug', trim($slug))
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRemoteConversation(array $row, Hub $hub): array
    {
        $lastMessageAt = $row['last_message_at'] ?? null;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'hub_user_id' => isset($row['hub_user_id']) ? (int) $row['hub_user_id'] : null,
            'hub_user_name' => $row['hub_user_name'] ?? null,
            'hub_user_email' => $row['hub_user_email'] ?? null,
            'central_user_id' => (int) ($row['central_user_id'] ?? 0),
            'central_user_name' => $row['central_user_name'] ?? null,
            'central_user_email' => $row['central_user_email'] ?? null,
            'status' => $row['status'] ?? LiveChatConversation::STATUS_OPEN,
            'last_message_at' => $lastMessageAt
                ? (is_string($lastMessageAt) ? $lastMessageAt : (string) $lastMessageAt)
                : null,
            'hub_unread_count' => (int) ($row['hub_unread_count'] ?? 0),
            'central_unread_count' => (int) ($row['central_unread_count'] ?? 0),
            'preview' => null,
            'source_hub_slug' => $hub->slug,
            'source_hub_name' => $hub->name,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRemoteMessage(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'conversation_id' => (int) ($row['conversation_id'] ?? 0),
            'sender_side' => $row['sender_side'] ?? null,
            'sender_user_id' => isset($row['sender_user_id']) ? (int) $row['sender_user_id'] : null,
            'sender_name' => $row['sender_name'] ?? null,
            'body' => $row['body'] ?? '',
            'read_at' => isset($row['read_at']) ? (string) $row['read_at'] : null,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }
}
