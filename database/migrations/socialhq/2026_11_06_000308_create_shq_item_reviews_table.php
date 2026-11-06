<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_item_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('shq_plan_items')->cascadeOnDelete();
            $table->foreignId('version_id')->nullable()->constrained('shq_item_versions')->nullOnDelete();
            $table->foreignId('brief_id')->nullable()->constrained('shq_briefs')->cascadeOnDelete();
            $table->unsignedInteger('round')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_item_reviews');
    }
};
