<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_past_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_import_id')->constrained('shq_calendar_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->date('posted_on');
            $table->string('platform', 40)->nullable();
            $table->string('channel_label', 100)->nullable();
            $table->string('format', 100)->nullable();
            $table->string('content_type', 60)->nullable();
            $table->string('title', 255)->nullable();
            $table->text('caption')->nullable();
            $table->string('pillar', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('engagement')->nullable();
            $table->unsignedInteger('reach')->nullable();
            $table->unsignedInteger('views')->nullable();
            $table->string('link', 2048)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'posted_on']);
            $table->index('calendar_import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_past_posts');
    }
};
