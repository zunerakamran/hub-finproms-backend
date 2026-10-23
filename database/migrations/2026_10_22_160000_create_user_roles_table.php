<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-role users (§2.7.1): pivot of all roles held by a user.
 * All assigned roles are equal. users.role is a legacy single-string mirror
 * (privilege-ordered) for audit / backward-compat call sites only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 64);
            $table->timestamps();

            $table->unique(['user_id', 'role']);
            $table->index('role');
        });

        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        $now = now();

        DB::table('users')
            ->select(['id', 'role'])
            ->whereNotNull('role')
            ->where('role', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($now): void {
                $rows = [];
                foreach ($users as $user) {
                    $role = strtolower(trim((string) $user->role));
                    if ($role === 'admin') {
                        $role = 'client_admin';
                    }
                    if ($role === '') {
                        continue;
                    }
                    $rows[] = [
                        'user_id' => (int) $user->id,
                        'role' => $role,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    DB::table('user_roles')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
