<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A brand's look: logo (private disk), colours, fonts and visual style notes.
        Schema::create('shq_brand_looks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('logo_path', 500)->nullable();
            $table->string('logo_mime', 40)->nullable();
            $table->unsignedInteger('logo_size')->nullable();
            $table->unsignedInteger('logo_width')->nullable();
            $table->unsignedInteger('logo_height')->nullable();
            // Up to 8 {name, hex} pairs, in the brand's order.
            $table->json('colours')->nullable();
            $table->string('heading_font', 80)->nullable();
            $table->string('body_font', 80)->nullable();
            $table->text('style_notes')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_brand_looks');
    }
};
