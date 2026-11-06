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
            // A posting time ("HH:MM") on the item's day, as a wall clock of suggested_timezone
            // (the brand's main audience zone when it was set).
            $table->string('suggested_time', 5)->nullable();
            $table->string('suggested_timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_plan_items', function (Blueprint $table) {
            $table->dropColumn(['suggested_time', 'suggested_timezone']);
        });
    }
};
