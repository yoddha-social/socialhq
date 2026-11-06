<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_ai_graphics', function (Blueprint $table) {
            // The brand look (colours, fonts, style) the image prompt was given, as sent.
            $table->text('brand_look')->nullable();
            // The brand's logo went in as the first reference image.
            $table->boolean('brand_logo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('shq_ai_graphics', function (Blueprint $table) {
            $table->dropColumn(['brand_look', 'brand_logo']);
        });
    }
};
