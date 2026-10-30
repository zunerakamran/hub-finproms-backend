<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff reply to support tickets: estimated resolve time on the ticket,
 * optional comment_id on attachments (so reply media sits with the activity
 * entry), and st_reply_to_tickets capability (seeded from st_change_ticket_status).
 */
return new class extends Migration
{
    private const KEY = 'st_reply_to_tickets';

    public function up(): void
    {
        if (Schema::hasTable('support_tickets')
            && ! Schema::hasColumn('support_tickets', 'estimated_resolve_at')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->timestamp('estimated_resolve_at')->nullable()->after('status_changed_at');
            });
        }

        if (Schema::hasTable('support_ticket_attachments')
            && ! Schema::hasColumn('support_ticket_attachments', 'comment_id')) {
            Schema::table('support_ticket_attachments', function (Blueprint $table) {
                $table->unsignedBigInteger('comment_id')->nullable()->after('ticket_id');
                $table->index(['comment_id'], 'st_attach_comment_idx');
            });
        }

        if (Schema::hasTable('support_ticket_comments')
            && ! Schema::hasColumn('support_ticket_comments', 'estimated_resolve_at')) {
            Schema::table('support_ticket_comments', function (Blueprint $table) {
                $table->timestamp('estimated_resolve_at')->nullable()->after('to_status');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }
                if (array_key_exists(self::KEY, $roleCaps[$role])) {
                    continue;
                }
                // Mirror existing status-changers so current inbox staff keep access.
                $roleCaps[$role][self::KEY] = ! empty($roleCaps[$role]['st_change_ticket_status']);
            }

            $any = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[self::KEY])) {
                    $any = true;
                    break;
                }
            }

            $checklist = $hub->resolvedChecklist();
            $checklist[self::KEY] = $any;

            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset($roleCaps[$role][self::KEY]);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist[self::KEY]);
            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });

        if (Schema::hasTable('support_ticket_comments')
            && Schema::hasColumn('support_ticket_comments', 'estimated_resolve_at')) {
            Schema::table('support_ticket_comments', function (Blueprint $table) {
                $table->dropColumn('estimated_resolve_at');
            });
        }

        if (Schema::hasTable('support_ticket_attachments')
            && Schema::hasColumn('support_ticket_attachments', 'comment_id')) {
            Schema::table('support_ticket_attachments', function (Blueprint $table) {
                $table->dropIndex('st_attach_comment_idx');
                $table->dropColumn('comment_id');
            });
        }

        if (Schema::hasTable('support_tickets')
            && Schema::hasColumn('support_tickets', 'estimated_resolve_at')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropColumn('estimated_resolve_at');
            });
        }
    }
};
