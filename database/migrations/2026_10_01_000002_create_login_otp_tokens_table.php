<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_otp_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('challenge');
            $table->string('code');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_otp_tokens');
    }
};
