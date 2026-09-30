<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_pricing', function (Blueprint $table) {
            if (! Schema::hasColumn('module_pricing', 'recurring_amount')) {
                $table->decimal('recurring_amount', 10, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('module_pricing', 'recurring_billing_unit')) {
                $table->string('recurring_billing_unit')->default('none')->after('recurring_amount');
            }
            if (! Schema::hasColumn('module_pricing', 'recurring_tier_slot')) {
                $table->unsignedTinyInteger('recurring_tier_slot')->nullable()->after('recurring_billing_unit');
            }
        });

        Schema::create('module_recurring_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('slot'); // 1|2|3
            $table->unsignedInteger('min_users')->default(0);
            $table->unsignedInteger('max_users')->nullable(); // null = onwards
            $table->decimal('rate_per_user', 10, 2)->default(0);
            $table->decimal('network_margin_per_user', 10, 2)->default(0);
            $table->string('currency', 3)->default('gbp');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['slot', 'min_users']);
        });

        // Slot defaults from catalogue images.
        $tiers = [
            // Slot #1 — Social Media Templates Library
            [1, 0, 100, 30.00, 0.00, 0],
            [1, 101, 250, 27.00, 3.00, 1],
            [1, 251, 500, 24.00, 6.00, 2],
            [1, 501, null, 21.00, 9.00, 3],
            // Slot #2 — Social Media Pre Approval
            [2, 0, 100, 75.00, 0.00, 0],
            [2, 101, 250, 70.00, 5.00, 1],
            [2, 251, 500, 60.00, 15.00, 2],
            [2, 501, null, 45.00, 30.00, 3],
            // Slot #3 — Generic Content Pre Approval (same rates as slot 2 in catalogue)
            [3, 0, 100, 75.00, 0.00, 0],
            [3, 101, 250, 70.00, 5.00, 1],
            [3, 251, 500, 60.00, 15.00, 2],
            [3, 501, null, 45.00, 30.00, 3],
        ];

        $now = now();
        foreach ($tiers as [$slot, $min, $max, $rate, $margin, $sort]) {
            DB::table('module_recurring_tiers')->insert([
                'slot' => $slot,
                'min_users' => $min,
                'max_users' => $max,
                'rate_per_user' => $rate,
                'network_margin_per_user' => $margin,
                'currency' => 'gbp',
                'is_active' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $recurringCatalogue = [
            'module_shared_hub' => [4500.00, 'per_network', null],
            'module_white_label_hub' => [4500.00, 'per_network', null],
            'module_social_media_template_library' => [0.00, 'per_adviser', 1],
            'module_social_media_compliance' => [0.00, 'per_user', 2],
            'module_website_template_library' => [125.00, 'per_website', null],
            'module_website_compliance' => [1500.00, 'per_firm', null],
            'module_general_compliance' => [0.00, 'per_user', 3],
            'module_central_hub' => [0.00, 'none', null],
        ];

        foreach ($recurringCatalogue as $key => [$amount, $unit, $slot]) {
            DB::table('module_pricing')
                ->where('module_key', $key)
                ->update([
                    'recurring_amount' => $amount,
                    'recurring_billing_unit' => $unit,
                    'recurring_tier_slot' => $slot,
                    'updated_at' => $now,
                ]);
        }

        Schema::create('module_billing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->unsignedTinyInteger('anniversary_day'); // 1–28
            $table->date('started_on');
            $table->date('next_invoice_on');
            $table->string('status')->default('active'); // active|canceled
            $table->unsignedInteger('initial_user_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['hub_id', 'module_key', 'status']);
            $table->index(['next_invoice_on', 'status']);
        });

        Schema::create('module_billing_batch_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_billing_batch_id')->constrained('module_billing_batches')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['module_billing_batch_id', 'user_id'], 'module_batch_user_unique');
        });

        Schema::create('hub_module_recurring_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_billing_batch_id')->nullable()->constrained('module_billing_batches')->nullOnDelete();
            $table->string('module_key');
            $table->foreignId('billed_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('user_count')->default(0);
            $table->unsignedInteger('total_users_for_tier')->default(0);
            $table->decimal('rate_per_user', 10, 2)->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('gbp');
            $table->string('status')->default('unpaid'); // unpaid|paid|canceled
            $table->string('payment_status')->default('unpaid');
            $table->date('due_on')->nullable();
            $table->date('period_starts_on')->nullable();
            $table->date('period_ends_on')->nullable();
            $table->string('billing_kind')->default('batch'); // batch|anniversary|flat
            $table->json('meta')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('payment_notes')->nullable();
            $table->timestamps();

            $table->index(['hub_id', 'status', 'due_on']);
            $table->index(['module_key', 'status']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'due_on')) {
                $table->date('due_on')->nullable()->after('issued_at');
            }
            if (! Schema::hasColumn('invoices', 'hub_module_recurring_billing_id')) {
                $table->foreignId('hub_module_recurring_billing_id')
                    ->nullable()
                    ->after('hub_module_billing_id')
                    ->constrained('hub_module_recurring_billings')
                    ->nullOnDelete();
            }
        });

        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'billing_grace_day')) {
                $table->unsignedTinyInteger('billing_grace_day')->nullable()->after('advisor_billing_renew_day');
            }
        });

        // Seed functionality onto existing hubs (WL on by default).
        $hubs = DB::table('hubs')->select('id', 'type', 'checklist')->get();
        foreach ($hubs as $hub) {
            $checklist = json_decode($hub->checklist ?? 'null', true);
            if (! is_array($checklist)) {
                $checklist = [];
            }
            if (! array_key_exists('charge_recurring_per_module', $checklist)) {
                $checklist['charge_recurring_per_module'] = $hub->type === 'white_label';
            }
            $renewDay = (int) (DB::table('hubs')->where('id', $hub->id)->value('advisor_billing_renew_day') ?: 1);
            $graceDay = min(28, max($renewDay, $renewDay + 3));
            DB::table('hubs')->where('id', $hub->id)->update([
                'checklist' => json_encode($checklist),
                'billing_grace_day' => $graceDay,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'hub_module_recurring_billing_id')) {
                $table->dropConstrainedForeignId('hub_module_recurring_billing_id');
            }
            if (Schema::hasColumn('invoices', 'due_on')) {
                $table->dropColumn('due_on');
            }
        });

        Schema::dropIfExists('hub_module_recurring_billings');
        Schema::dropIfExists('module_billing_batch_users');
        Schema::dropIfExists('module_billing_batches');
        Schema::dropIfExists('module_recurring_tiers');

        Schema::table('module_pricing', function (Blueprint $table) {
            foreach (['recurring_amount', 'recurring_billing_unit', 'recurring_tier_slot'] as $col) {
                if (Schema::hasColumn('module_pricing', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'billing_grace_day')) {
                $table->dropColumn('billing_grace_day');
            }
        });
    }
};
