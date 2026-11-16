<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings › AI › Usage: one row per AI call through the LiteLLM gateway, and
 * one per call the app refused before sending (no route). Metadata only:
 * never a prompt, an answer or a provider's error text. Kept 90 days
 * (AiUsageLog::RETENTION_DAYS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_ai_usage_logs', function (Blueprint $table) {
            $table->id();
            // Null for calls that run for the platform itself, not a company.
            $table->foreignUuid('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 64);
            $table->string('task', 16)->nullable();
            // own / platform / none (CompanyRoute::$kind).
            $table->string('route', 16)->nullable();
            $table->string('status', 24);
            // Our id for the request, sent as x-litellm-call-id: LiteLLM's spend log row has it too.
            $table->string('litellm_call_id', 64)->nullable()->index();
            $table->string('model_group_requested')->nullable();
            $table->string('model_group')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model')->nullable();
            $table->string('deployment')->nullable();
            $table->unsignedTinyInteger('fallbacks')->default(0);
            $table->unsignedSmallInteger('requests')->default(0);
            $table->decimal('cost', 14, 8)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('error_code')->nullable();
            $table->string('error_type', 64)->nullable();
            $table->timestamps();

            $table->index(['account_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_ai_usage_logs');
    }
};
