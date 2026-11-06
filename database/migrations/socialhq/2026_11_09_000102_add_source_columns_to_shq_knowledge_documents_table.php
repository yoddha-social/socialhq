<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_knowledge_documents', function (Blueprint $table) {
            // Where the text comes from: an upload, a Google Docs, Sheets or Slides link, or the website.
            $table->string('source', 20)->default('upload');
            // The link a linked document is read from again ("Read again").
            $table->string('source_url', 500)->nullable();
            // A hash of what was last read, so reading an unchanged file again costs nothing.
            $table->string('source_hash', 64)->nullable();
            // The title came from the file itself (it follows the file's name when read again).
            $table->boolean('auto_title')->default(false);
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_knowledge_documents', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_url', 'source_hash', 'auto_title', 'read_at']);
        });
    }
};
