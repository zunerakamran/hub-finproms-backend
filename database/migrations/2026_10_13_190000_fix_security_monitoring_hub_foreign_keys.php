<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security monitoring tables are read/written on the acting hub DB (UsesWcDatabaseContext).
 * FK to hubs.id fails there because hub_id is the Central registry id, not the remote hubs.id.
 * Drop FKs; keep nullable hub_id as a soft reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['security_events', 'security_incidents', 'security_monitoring_settings'] as $table) {
            $this->dropHubForeignKey($table);
        }
    }

    public function down(): void
    {
        // Non-reversible safely across remote hubs — leave without FKs.
    }

    private function dropHubForeignKey(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'hub_id')) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['hub_id']);
            });
        } catch (\Throwable) {
            // Already dropped, sqlite, or differently named.
        }
    }
};
