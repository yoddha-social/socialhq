<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Search's word counts, stored when the text is written so search
     * doesn't split every excerpt and entry again on each AI request. Null
     * until filled: search fills existing rows the first time it reads them
     * (or `php artisan socialhq:knowledge:index`).
     */
    public function up(): void
    {
        Schema::table('shq_knowledge_chunks', function (Blueprint $table) {
            $table->json('terms')->nullable();
        });

        Schema::table('shq_knowledge_entries', function (Blueprint $table) {
            $table->json('terms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_knowledge_chunks', function (Blueprint $table) {
            $table->dropColumn('terms');
        });

        Schema::table('shq_knowledge_entries', function (Blueprint $table) {
            $table->dropColumn('terms');
        });
    }
};
