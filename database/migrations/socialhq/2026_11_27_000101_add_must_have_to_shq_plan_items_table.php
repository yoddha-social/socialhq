<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_plan_items', function (Blueprint $table) {
            // The must-have post this item is (Plan\MustHave::$key), or null: the team asked for it in
            // "Generate with AI", and the planner job made sure the plan has it. Cards show "Must-have".
            $table->string('must_have', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_plan_items', function (Blueprint $table) {
            $table->dropColumn('must_have');
        });
    }
};
