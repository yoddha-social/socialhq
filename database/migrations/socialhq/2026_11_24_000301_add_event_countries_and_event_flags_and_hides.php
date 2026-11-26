<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            // The countries whose dates this brand plans around (ISO codes); null: its audience's, else Nepal.
            $table->json('event_countries')->nullable();
        });

        // A shared date a brand has no use for: hidden for that brand only.
        Schema::create('shq_event_hides', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('shq_events')->cascadeOnDelete();
            $table->foreignUuid('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'event_id']);
        });

        // A member's note that a date looks wrong, for the brand's approvers.
        Schema::create('shq_event_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('shq_events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'event_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_event_flags');
        Schema::dropIfExists('shq_event_hides');

        Schema::table('shq_brand_profiles', function (Blueprint $table) {
            $table->dropColumn('event_countries');
        });
    }
};
