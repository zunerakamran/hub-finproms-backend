<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-firm Delegated compliance access (§2.7.0a).
 * Replaces single compliance_visible_to_firm_id + compliance_visible_to_central
 * as the source of truth for which firms may review/assign/report/audit a subject firm.
 * Legacy columns are kept and mirrored for transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('firm_compliance_authorities')) {
            Schema::create('firm_compliance_authorities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subject_firm_id')
                    ->constrained('firms')
                    ->cascadeOnDelete();
                $table->foreignId('authority_firm_id')
                    ->constrained('firms')
                    ->cascadeOnDelete();
                $table->timestamps();

                $table->unique(
                    ['subject_firm_id', 'authority_firm_id'],
                    'firm_compliance_auth_subject_authority_unique'
                );
                $table->index(['authority_firm_id'], 'firm_compliance_auth_authority_idx');
            });
        }

        if (! Schema::hasTable('firms')) {
            return;
        }

        $centralId = DB::table('firms')->where('is_central', true)->value('id');
        $now = now();

        $rows = DB::table('firms')->select([
            'id',
            'compliance_visible_to_central',
            'compliance_visible_to_firm_id',
        ])->get();

        foreach ($rows as $row) {
            $subjectId = (int) $row->id;
            $insert = [];

            if ($centralId
                && (bool) ($row->compliance_visible_to_central ?? false)
                && (int) $centralId !== $subjectId
            ) {
                $insert[] = [
                    'subject_firm_id' => $subjectId,
                    'authority_firm_id' => (int) $centralId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $peerId = $row->compliance_visible_to_firm_id
                ? (int) $row->compliance_visible_to_firm_id
                : null;
            if ($peerId && $peerId !== $subjectId) {
                $insert[] = [
                    'subject_firm_id' => $subjectId,
                    'authority_firm_id' => $peerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach ($insert as $payload) {
                $exists = DB::table('firm_compliance_authorities')
                    ->where('subject_firm_id', $payload['subject_firm_id'])
                    ->where('authority_firm_id', $payload['authority_firm_id'])
                    ->exists();
                if (! $exists) {
                    DB::table('firm_compliance_authorities')->insert($payload);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_compliance_authorities');
    }
};
