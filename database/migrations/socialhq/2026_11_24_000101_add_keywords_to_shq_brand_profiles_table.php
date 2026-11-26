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
            // SEO keywords: [{text, main}], in the order the admin set (Knowledge\Keywords\BrandKeywords).
            $table->json('keywords')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
