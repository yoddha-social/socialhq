<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_calendar_imports', function (Blueprint $table) {
            // A hash of the uploaded file, so the same file isn't imported twice into one brand.
            $table->string('content_hash', 64)->nullable();

            $table->index(['workspace_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('shq_calendar_imports', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
