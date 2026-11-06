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
            // Where the audience is: [{country, city, timezone, share}], largest share first.
            $table->json('audience_locations')->nullable();
            // The "Generate with AI" switches the team used last for this brand.
            $table->json('planner_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            $table->dropColumn(['audience_locations', 'planner_options']);
        });
    }
};
