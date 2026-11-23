<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which posts were made from which plan item: written when the composer
 * saves the post, not when it opens. A post comes from one item at most;
 * deleting either side deletes the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_plan_item_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('plan_item_id')->constrained('shq_plan_items')->cascadeOnDelete();
            $table->foreignUuid('post_id')->unique()->constrained('posts')->cascadeOnDelete();
            $table->foreignUuid('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('plan_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_plan_item_posts');
    }
};
