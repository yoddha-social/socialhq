<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('file_name', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->string('path', 500);
            $table->string('status', 20);
            // A reason key the page translates, never an exception message.
            $table->string('failure', 40)->nullable();
            $table->unsignedInteger('pages')->nullable();
            $table->unsignedInteger('characters')->default(0);
            $table->boolean('truncated')->default(false);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->boolean('use_in_ai')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_knowledge_documents');
    }
};
