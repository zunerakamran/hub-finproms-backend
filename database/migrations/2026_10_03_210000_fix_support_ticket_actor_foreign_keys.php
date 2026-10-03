<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Power Admin / remote actors often write tickets on a hub DB where their
        // users.id does not exist — FK constraints then 500 on status/comments.
        if (Schema::hasTable('support_tickets')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                try {
                    $table->dropForeign(['status_changed_by']);
                } catch (\Throwable) {
                    // Already dropped / named differently.
                }
            });
        }

        if (Schema::hasTable('support_ticket_comments')) {
            Schema::table('support_ticket_comments', function (Blueprint $table) {
                try {
                    $table->dropForeign(['user_id']);
                } catch (\Throwable) {
                    // Already dropped / named differently.
                }

                if (! Schema::hasColumn('support_ticket_comments', 'author_name')) {
                    $table->string('author_name')->nullable()->after('user_id');
                }
            });
        }

        if (Schema::hasTable('support_ticket_attachments')) {
            Schema::table('support_ticket_attachments', function (Blueprint $table) {
                try {
                    $table->dropForeign(['uploaded_by_user_id']);
                } catch (\Throwable) {
                    // Already dropped / named differently.
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('support_ticket_comments') && Schema::hasColumn('support_ticket_comments', 'author_name')) {
            Schema::table('support_ticket_comments', function (Blueprint $table) {
                $table->dropColumn('author_name');
            });
        }
    }
};
