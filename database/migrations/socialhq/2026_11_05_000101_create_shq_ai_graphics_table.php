<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_ai_graphics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('import_id')->unique();
            $table->text('prompt');
            $table->string('format', 20);
            $table->unsignedTinyInteger('reference_count')->default(0);
            $table->string('provider', 30);
            $table->string('model')->nullable();
            $table->string('status', 10);
            // Why the vision check blocked it, or why generation failed.
            $table->string('reason', 500)->nullable();
            $table->uuid('media_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_ai_graphics');
    }
};
