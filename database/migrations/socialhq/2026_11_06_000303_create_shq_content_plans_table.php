<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_content_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('status', 20)->default('draft');
            $table->text('goals')->nullable();
            $table->string('generation_status', 20)->default('idle');
            $table->string('generation_error', 500)->nullable();
            $table->text('generation_summary')->nullable();
            $table->json('generation_rejections')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_content_plans');
    }
};
