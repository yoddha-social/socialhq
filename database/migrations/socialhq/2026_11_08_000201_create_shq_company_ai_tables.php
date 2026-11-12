<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company's own AI: its LiteLLM team and virtual key. Provider keys
        // are never stored here; they live only in LiteLLM.
        Schema::create('shq_company_ai', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('litellm_team_id', 80)->nullable()->unique();
            // Encrypted with SOCIALHQ_SECRETS_KEY (never APP_KEY).
            $table->text('virtual_key')->nullable();
            $table->decimal('monthly_budget_usd', 10, 2)->nullable();
            $table->string('budget_duration', 10)->default('30d');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        // One row per provider key a company added: what it is, never the key.
        Schema::create('shq_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('litellm_credential_name', 120)->unique();
            $table->string('last4', 4);
            // Non-secret settings, such as a Cloudflare account id.
            $table->json('options')->nullable();
            $table->string('status', 20)->default('untested');
            $table->timestamp('last_tested_at')->nullable();
            // A lang key under socialhq_ai.errors, never the provider's text.
            $table->string('last_error', 60)->nullable();
            // Fallback order: the lowest is tried first.
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamp('data_confirmed_at')->nullable();
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'provider']);
        });

        // Who changed a company's AI, and what. No secrets.
        Schema::create('shq_company_ai_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('provider', 40)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_company_ai_events');
        Schema::dropIfExists('shq_provider_credentials');
        Schema::dropIfExists('shq_company_ai');
    }
};
