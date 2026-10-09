<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Removed: duplicate of Support Tickets for breach/incident tracking.
        Schema::dropIfExists('gdpr_incidents');
    }

    public function down(): void
    {
        // Intentionally empty — incident log was retired in favour of Support Tickets.
    }
};
