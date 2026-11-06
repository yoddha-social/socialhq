<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Read our website": each run, what it read and what the AI suggested (kept until reviewed).
        Schema::create('shq_website_crawls', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('status', 20);
            // A reason key the page translates, never an exception message.
            $table->string('failure', 40)->nullable();
            $table->unsignedInteger('pages_read')->default(0);
            $table->unsignedInteger('pages_unchanged')->default(0);
            $table->unsignedInteger('pages_skipped')->default(0);
            $table->unsignedInteger('characters')->default(0);
            // A hash of the text sent to the AI: the same text is not sent twice.
            $table->string('input_hash', 64)->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 160)->nullable();
            $table->json('suggestions')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        // The cleaned text of each page last read, to skip pages that haven't changed.
        Schema::create('shq_website_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('etag', 200)->nullable();
            $table->string('last_modified', 100)->nullable();
            // A hash of the HTML as served: the same HTML is not cleaned again.
            $table->string('content_hash', 64);
            $table->string('title', 300)->nullable();
            $table->text('text');
            $table->timestamps();

            $table->unique(['workspace_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_website_pages');
        Schema::dropIfExists('shq_website_crawls');
    }
};
