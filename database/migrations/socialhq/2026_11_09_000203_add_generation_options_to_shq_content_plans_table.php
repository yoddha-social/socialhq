<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_content_plans', function (Blueprint $table) {
            // What the latest AI draft was asked for: its days and the sources it read (PlannerOptions).
            $table->json('generation_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_content_plans', function (Blueprint $table) {
            $table->dropColumn('generation_options');
        });
    }
};
