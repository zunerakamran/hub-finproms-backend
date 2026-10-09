<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (! Schema::hasColumn('categories', 'icon')) {
                $table->string('icon', 64)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('categories', 'icon_path')) {
                $table->string('icon_path', 1000)->nullable()->after('icon');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (Schema::hasColumn('categories', 'icon_path')) {
                $table->dropColumn('icon_path');
            }
            if (Schema::hasColumn('categories', 'icon')) {
                $table->dropColumn('icon');
            }
        });
    }
};
