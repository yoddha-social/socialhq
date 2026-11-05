<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->unique()->constrained('shq_plan_items')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('stage', 30)->default('todo');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->text('format_notes')->nullable();
            $table->text('on_screen_text')->nullable();
            $table->text('direction')->nullable();
            $table->text('references')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'stage', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_briefs');
    }
};
