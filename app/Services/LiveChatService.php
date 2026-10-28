<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\LiveChatConversation;
use App\Models\LiveChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        if (! $this->matrix->roleCan($hub, $role, 'lc_use_chat')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to use Live Chat.',
            ]);
        }

        // Central agents must also be checked in the access picker.
        if ($this->isCentralAgentContext($hub, $user) && ! $this->userHasLiveChatAccess($user)) {
            throw ValidationException::withMessages([
                'live_chat_access' => 'Your Central Hub account is not enabled for Live Chat. Ask an admin to check you under Live Chat access.',
            ]);
        }
    }

    public function assertCanManageAccess(Hub $hub, User $user): void
    {
        if (! $hub->isCentral() && ! (bool) config('hub.is_control_plane', false)) {
            throw ValidationException::withMessages([
                'live_chat' => 'Live Chat access is managed only on the Central Hub.',
            ]);
        }

        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        $can = $this->matrix->roleCan($hub, $role, 'lc_manage_access')
            || $user->isPowerAdmin();
        if (! $can) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to manage Live Chat access.',
            ]);
        }
    }

    /**
     * Whether this request is from a Central Hub agent (not a content-hub member).
     */
    public function isCentralAgentContext(Hub $hub, User $user): bool
    {
        if ($hub->isCentral() || (bool) config('hub.is_control_plane', false)) {
            // On Central, everyone with access is an agent talking to content-hub users.
            return true;
        }

        return false;
    }

    public function userHasLiveChatAccess(User $user): bool
    {
        if (! Schema::hasColumn('users', 'live_chat_access')) {
            return false;
        }

        return (bool) $user->live_chat_access || $user->isPowerAdmin();
    }

    /**
     * Agents hub users may start a chat with (synced onto content hubs).
     *
     * @return list<array{id:int,name:string,email:string}>
     */
    public function availableAgents(Hub $hub): array
    {
        if ($hub->isCentral() || (bool) config('hub.is_control_plane', false)) {
            return $this->centralAgentsFromLocalUsers();
        }

        $agents = is_array($hub->live_chat_agents) ? $hub->live_chat_agents : [];
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
     * @return list<array{id:int,name:string,email:string,live_chat_access:bool,role:?string}>
     */
    public function accessPickerUsers(): array
    {
        if (! Schema::hasColumn('users', 'live_chat_access')) {
            return [];
        }

        return User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'live_chat_access'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'role_label' => $u->role_label ?? null,
                'live_chat_access' => (bool) $u->live_chat_access,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $enabledUserIds
     * @return list<array{id:int,name:string,email:string}>
     */
    public function saveAccess(Hub $hub, User $actor, array $enabledUserIds): array
    {
        $this->assertCanManageAccess($hub, $actor);

        if (! Schema::hasColumn('users', 'live_chat_access')) {
            throw ValidationException::withMessages([
                'live_chat_access' => 'Live Chat access column is missing. Run migrations.',
            ]);
        }

        $ids = array_values(array_unique(array_map('intval', $enabledUserIds)));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));

        User::query()->update(['live_chat_access' => false]);
        if ($ids !== []) {
            User::query()->whereIn('id', $ids)->update(['live_chat_access' => true]);
        }

        $agents = $this->centralAgentsFromLocalUsers();
        $this->syncAgentsToContentHubs($agents);

        $this->activity->log([
            'action' => 'live_chat.access_updated',
            'description' => 'Updated Live Chat Central access ('.count($agents).' agents)',
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

        if ($conversation->status === LiveChatConversation::STATUS_CLOSED) {
            $conversation->status = LiveChatConversation::STATUS_OPEN;
            $conversation->save();
        }

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
     * @return array<string, mixed>
     */
    public function closeConversation(
        Hub $hub,
        User $user,
        int $conversationId,
        ?string $sourceHubSlug = null
    ): array {
        $this->assertCanUse($hub, $user);

        if ($this->isCentralAgentContext($hub, $user)) {
            return $this->closeCentralAgentConversation($user, $conversationId, $sourceHubSlug);
        }

        $conversation = LiveChatConversation::query()
            ->where('hub_user_id', $user->id)
            ->whereKey($conversationId)
            ->firstOrFail();

        $conversation->status = LiveChatConversation::STATUS_CLOSED;
        $conversation->save();

        return $conversation->toApiArray();
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
     * @return list<array{id:int,name:string,email:string}>
     */
    private function centralAgentsFromLocalUsers(): array
    {
        if (! Schema::hasColumn('users', 'live_chat_access')) {
            return [];
        }

        return User::query()
            ->where('live_chat_access', true)
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{id:int,name:string,email:string}>  $agents
     */
    private function syncAgentsToContentHubs(array $agents): void
    {
        $payload = json_encode(array_values($agents));

        // Update Central registry rows for content hubs.
        Hub::query()
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->orderBy('id')
            ->each(function (Hub $hub) use ($agents, $payload) {
                if (Schema::hasColumn('hubs', 'live_chat_agents')) {
                    $hub->live_chat_agents = $agents;
                    $hub->save();
                }

                if (! $hub->hasRemoteDatabaseConfigured()) {
                    return;
                }

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
            });
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

    /**
     * @return array<string, mixed>
     */
    private function closeCentralAgentConversation(User $user, int $conversationId, ?string $sourceHubSlug): array
    {
        $hub = $this->resolveSourceHub($sourceHubSlug);
        if (! $hub) {
            throw ValidationException::withMessages([
                'source_hub_slug' => 'source_hub_slug is required for Central Live Chat conversations.',
            ]);
        }

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $user, $conversationId) {
            $updated = DB::connection($connection)->table('live_chat_conversations')
                ->where('id', $conversationId)
                ->where('central_user_id', $user->id)
                ->update([
                    'status' => LiveChatConversation::STATUS_CLOSED,
                    'updated_at' => now(),
                ]);

            if (! $updated) {
                throw ValidationException::withMessages([
                    'conversation' => 'Conversation not found.',
                ]);
            }

            $row = DB::connection($connection)->table('live_chat_conversations')
                ->where('id', $conversationId)
                ->first();

            return $this->mapRemoteConversation((array) $row, $hub);
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
