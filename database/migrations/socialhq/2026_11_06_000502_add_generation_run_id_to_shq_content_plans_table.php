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
            // The AI run the plan is waiting for: a job whose run is no longer this one writes nothing.
            $table->uuid('generation_run_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_content_plans', function (Blueprint $table) {
            $table->dropColumn('generation_run_id');
        });
    }
};
