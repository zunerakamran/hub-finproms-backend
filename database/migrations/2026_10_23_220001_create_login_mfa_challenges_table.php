<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_mfa_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('challenge');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_mfa_challenges');
    }
};
