<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('live_chat_conversations')) {
            Schema::create('live_chat_conversations', function (Blueprint $table) {
                $table->id();
                // Hub-side participant (local users table when on a content hub).
                $table->unsignedBigInteger('hub_user_id')->nullable();
                $table->string('hub_user_name')->nullable();
                $table->string('hub_user_email')->nullable();
                // Central participant — opaque Central users.id (no FK across DBs).
                $table->unsignedBigInteger('central_user_id');
                $table->string('central_user_name')->nullable();
                $table->string('central_user_email')->nullable();
                $table->string('status', 40)->default('open');
                $table->timestamp('last_message_at')->nullable();
                $table->unsignedInteger('hub_unread_count')->default(0);
                $table->unsignedInteger('central_unread_count')->default(0);
                $table->timestamps();

                $table->index(['hub_user_id', 'status'], 'lc_hub_user_status_idx');
                $table->index(['central_user_id', 'status'], 'lc_central_user_status_idx');
                $table->index(['last_message_at'], 'lc_last_message_idx');
                $table->unique(
                    ['hub_user_id', 'central_user_id'],
                    'lc_hub_central_unique'
                );
            });
        }

        if (! Schema::hasTable('live_chat_messages')) {
            Schema::create('live_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')
                    ->constrained('live_chat_conversations')
                    ->cascadeOnDelete();
                // hub | central
                $table->string('sender_side', 20);
                $table->unsignedBigInteger('sender_user_id')->nullable();
                $table->string('sender_name')->nullable();
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'created_at'], 'lc_msg_conv_created_idx');
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'live_chat_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('live_chat_access')->default(false)->after('mfa_confirmed_at');
            });
        }

        if (Schema::hasTable('hubs') && ! Schema::hasColumn('hubs', 'live_chat_agents')) {
            Schema::table('hubs', function (Blueprint $table) {
                $table->json('live_chat_agents')->nullable()->after('checklist');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $checklist = $hub->resolvedChecklist();

            if (! array_key_exists('live_chat', $checklist)) {
                $checklist['live_chat'] = false;
            }

            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (Hub::LIVE_CHAT_CAPABILITY_KEYS as $key) {
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = false;
                    }
                }
            }

            foreach (Hub::LIVE_CHAT_CAPABILITY_KEYS as $key) {
                $any = false;
                foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                    if (! empty($roleCaps[$role][$key])) {
                        $any = true;
                        break;
                    }
                }
                $checklist[$key] = $any;
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_messages');
        Schema::dropIfExists('live_chat_conversations');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'live_chat_access')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('live_chat_access');
            });
        }

        if (Schema::hasTable('hubs') && Schema::hasColumn('hubs', 'live_chat_agents')) {
            Schema::table('hubs', function (Blueprint $table) {
                $table->dropColumn('live_chat_agents');
            });
        }

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['live_chat']);
            foreach (Hub::LIVE_CHAT_CAPABILITY_KEYS as $key) {
                unset($checklist[$key]);
            }

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach (Hub::LIVE_CHAT_CAPABILITY_KEYS as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }
};
