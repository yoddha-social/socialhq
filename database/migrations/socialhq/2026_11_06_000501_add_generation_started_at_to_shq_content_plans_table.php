<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_content_plans', function (Blueprint $table) {
            // When the running AI draft started: a run older than 10 minutes counts as stale.
            $table->timestamp('generation_started_at')->nullable();
            $table->foreignUuid('generation_requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shq_content_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('generation_requested_by');
            $table->dropColumn('generation_started_at');
        });
    }
};
