<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('shq_content_plans')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_on');
            $table->foreignUuid('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 40)->nullable();
            $table->string('content_type', 40)->nullable();
            $table->string('creative', 20)->default('image');
            $table->string('pillar', 60)->nullable();
            $table->string('title', 160);
            $table->text('objective')->nullable();
            $table->text('angle')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('shq_events')->nullOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('planned');
            $table->string('source', 10)->default('manual');
            $table->unsignedInteger('position')->default(0);
            $table->string('copy_status', 20)->default('none');
            $table->timestamp('post_created_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'scheduled_on']);
            $table->index(['plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_plan_items');
    }
};
