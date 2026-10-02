<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wc_templates') && ! Schema::hasColumn('wc_templates', 'available_pages')) {
            Schema::table('wc_templates', function (Blueprint $table) {
                $table->json('available_pages')->nullable()->after('color_schemes');
            });
        }

        if (Schema::hasTable('wc_template_requests')) {
            Schema::table('wc_template_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('wc_template_requests', 'services')) {
                    $table->json('services')->nullable()->after('secondary_color');
                }
                if (! Schema::hasColumn('wc_template_requests', 'images')) {
                    $table->json('images')->nullable()->after('services');
                }
                if (! Schema::hasColumn('wc_template_requests', 'contact_details')) {
                    $table->json('contact_details')->nullable()->after('images');
                }
                if (! Schema::hasColumn('wc_template_requests', 'policies')) {
                    $table->json('policies')->nullable()->after('contact_details');
                }
                if (! Schema::hasColumn('wc_template_requests', 'selected_pages')) {
                    $table->json('selected_pages')->nullable()->after('policies');
                }
                if (! Schema::hasColumn('wc_template_requests', 'page_contents')) {
                    $table->json('page_contents')->nullable()->after('selected_pages');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wc_templates') && Schema::hasColumn('wc_templates', 'available_pages')) {
            Schema::table('wc_templates', function (Blueprint $table) {
                $table->dropColumn('available_pages');
            });
        }

        if (Schema::hasTable('wc_template_requests')) {
            Schema::table('wc_template_requests', function (Blueprint $table) {
                foreach (['services', 'images', 'contact_details', 'policies', 'selected_pages', 'page_contents'] as $col) {
                    if (Schema::hasColumn('wc_template_requests', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
