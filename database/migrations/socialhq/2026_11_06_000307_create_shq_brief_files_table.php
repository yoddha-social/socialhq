<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_brief_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brief_id')->constrained('shq_briefs')->cascadeOnDelete();
            $table->unsignedInteger('round');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->string('note', 500)->nullable();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['brief_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_brief_files');
    }
};
