<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who changed access, and how (App\SocialHq\Access\AccessLog): roles
 * created, changed and deleted, a member's role changed, invites sent,
 * access requests approved and members removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            // Null for a change to a role, which reaches every workspace.
            $table->foreignUuid('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            // Who did it; null for the system (or once they're deleted).
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);
            // Kept after the role or the person is deleted (removing someone
            // can delete their user): the history outlives both.
            $table->uuid('role_id')->nullable()->index();
            $table->uuid('member_id')->nullable()->index();
            // Names, emails and permissions as they were, so the history reads without the rows.
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_access_events');
    }
};
