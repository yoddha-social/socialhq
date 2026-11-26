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
            // How each week of the latest AI draft went: [{from, to, status, error, proposed, added, not_added, repaired, calls}]
            // (GeneratePlanSuggestions, one AI call per week).
            $table->json('generation_weeks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_content_plans', function (Blueprint $table) {
            $table->dropColumn('generation_weeks');
        });
    }
};
