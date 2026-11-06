<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            // Content pillars (up to 8 names) and posts per week per channel, read by the planner.
            $table->json('pillars')->nullable();
            $table->json('cadence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            $table->dropColumn(['pillars', 'cadence']);
        });
    }
};
