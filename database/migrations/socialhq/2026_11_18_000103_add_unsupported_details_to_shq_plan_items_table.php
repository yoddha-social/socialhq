<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Names, numbers and quotes in an AI-planned item's objective and angle that
 * the planner's sources (the brand, the events, past posts) don't contain.
 * Cleared when someone edits the item.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shq_plan_items', 'unsupported_details')) {
            Schema::table('shq_plan_items', fn (Blueprint $table) => $table->json('unsupported_details')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shq_plan_items', 'unsupported_details')) {
            Schema::table('shq_plan_items', fn (Blueprint $table) => $table->dropColumn('unsupported_details'));
        }
    }
};
