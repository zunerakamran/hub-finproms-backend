<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared hubs were healed to receive Central distribute pushes; white-labelled
 * hubs were not. Enable receive_content_from_shared (and a posts mode) on
 * active white-label registry rows so they appear as distribute targets when
 * SMTL + remote DB are also ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $hubs = DB::table('hubs')
            ->where('type', Hub::TYPE_WHITE_LABEL)
            ->where('is_active', true)
            ->get();

        foreach ($hubs as $hub) {
            $checklist = json_decode($hub->checklist ?? '{}', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }

            $checklist['receive_content_from_shared'] = true;

            $manual = filter_var($checklist['manual_posts'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $ai = filter_var($checklist['ai_posts'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if (! $manual && ! $ai) {
                $checklist['manual_posts'] = true;
                $checklist['ai_posts'] = false;
            }

            DB::table('hubs')->where('id', $hub->id)->update([
                'checklist' => json_encode($checklist),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible data heal — no-op.
    }
};
