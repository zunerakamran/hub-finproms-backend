<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;

/**
 * Collapse st_change_ticket_status + st_comment_on_tickets into st_reply_to_tickets.
 * View-all remains a separate read-only queue/detail capability.
 */
return new class extends Migration
{
    private const REPLY = 'st_reply_to_tickets';

    /** @var list<string> */
    private const REMOVE = [
        'st_change_ticket_status',
        'st_comment_on_tickets',
    ];

    public function up(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }

                $hadReplyRelated = ! empty($caps[self::REPLY])
                    || ! empty($caps['st_change_ticket_status'])
                    || ! empty($caps['st_comment_on_tickets']);

                $caps[self::REPLY] = $hadReplyRelated;

                foreach (self::REMOVE as $key) {
                    unset($caps[$key]);
                }

                $roleCaps[$role] = $caps;
            }

            $anyReply = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[self::REPLY])) {
                    $anyReply = true;
                    break;
                }
            }

            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            $checklist[self::REPLY] = $anyReply;
            foreach (self::REMOVE as $key) {
                unset($checklist[$key]);
            }

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
                if (! is_array($caps)) {
                    continue;
                }
                $replyOn = ! empty($caps[self::REPLY]);
                // Restore both legacy keys from reply (best-effort).
                $caps['st_change_ticket_status'] = $replyOn;
                $caps['st_comment_on_tickets'] = $replyOn;
                $roleCaps[$role] = $caps;
            }

            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            $anyReply = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[self::REPLY])) {
                    $anyReply = true;
                    break;
                }
            }
            $checklist['st_change_ticket_status'] = $anyReply;
            $checklist['st_comment_on_tickets'] = $anyReply;

            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });
    }
};
