<?php

use App\Models\Firm;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->boolean('is_central')->default(false)->after('name');
            $table->boolean('compliance_visible_to_own')->default(true)->after('is_central');
            $table->boolean('compliance_visible_to_central')->default(false)->after('compliance_visible_to_own');
            $table->foreignId('compliance_visible_to_firm_id')
                ->nullable()
                ->after('compliance_visible_to_central')
                ->constrained('firms')
                ->nullOnDelete();
        });

        // Ensure the single Central / Network firm exists.
        if (! Firm::query()->where('is_central', true)->exists()) {
            Firm::query()->create([
                'name' => Firm::CENTRAL_DEFAULT_NAME,
                'is_central' => true,
                'compliance_visible_to_own' => true,
                'compliance_visible_to_central' => true,
                'compliance_visible_to_firm_id' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compliance_visible_to_firm_id');
            $table->dropColumn([
                'is_central',
                'compliance_visible_to_own',
                'compliance_visible_to_central',
            ]);
        });
    }
};
