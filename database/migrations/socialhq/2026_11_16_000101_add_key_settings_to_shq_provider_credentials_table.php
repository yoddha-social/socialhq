<?php

declare(strict_types=1);

use App\SocialHq\Ai\Company\CredentialTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company may add several keys for one provider, each with its own
     * name, plan (free or paid), tasks and models.
     */
    public function up(): void
    {
        // The plain index first: on MySQL the unique backs the account_id foreign key.
        Schema::table('shq_provider_credentials', function (Blueprint $table) {
            $table->index(['account_id', 'provider']);
        });

        Schema::table('shq_provider_credentials', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'provider']);

            // The owner's name for the key, unique per company. Null on keys added before names.
            $table->string('label', 60)->nullable()->after('provider');
            // The provider's plan the key is on (CredentialTier).
            $table->string('tier', 10)->default(CredentialTier::DEFAULT->value)->after('label');
            // The tasks the key serves; null: every task its plan offers.
            $table->json('tasks')->nullable()->after('options');
            // Per task, a model chosen instead of the catalogue's; null: the catalogue's.
            $table->json('models')->nullable()->after('tasks');
        });

        // Keys added before plans: Groq, Cloudflare and Mistral were used on
        // their free plans; Gemini needed billing; OpenAI and Anthropic are paid.
        DB::table('shq_provider_credentials')
            ->whereIn('provider', ['groq', 'cloudflare', 'mistral'])
            ->update(['tier' => CredentialTier::Free->value]);
    }

    public function down(): void
    {
        Schema::table('shq_provider_credentials', function (Blueprint $table) {
            $table->dropColumn(['label', 'tier', 'tasks', 'models']);
            $table->unique(['account_id', 'provider']);
        });

        Schema::table('shq_provider_credentials', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'provider']);
        });
    }
};
