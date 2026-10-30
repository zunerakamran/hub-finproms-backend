<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'training_attended')) {
                $table->boolean('training_attended')->default(false)->after('avatar_path');
            }
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'status_remarks')) {
                $table->text('status_remarks')->nullable()->after('discontinued_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'training_attended')) {
                $table->dropColumn('training_attended');
            }
            if (Schema::hasColumn('users', 'password_changed_at')) {
                $table->dropColumn('password_changed_at');
            }
            if (Schema::hasColumn('users', 'status_remarks')) {
                $table->dropColumn('status_remarks');
            }
        });
    }
};
