<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            // Kept without a constraint: a notice outlives its post or channel and says so.
            $table->uuid('post_id')->nullable();
            $table->uuid('social_account_id')->nullable();
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'workspace_id', 'created_at']);
            $table->index(['user_id', 'workspace_id', 'read_at']);
        });

        Schema::create('shq_notice_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            // Only what the person turned off is stored: a missing key means on.
            $table->json('in_app');
            $table->boolean('live')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_notice_preferences');
        Schema::dropIfExists('shq_notices');
    }
};
