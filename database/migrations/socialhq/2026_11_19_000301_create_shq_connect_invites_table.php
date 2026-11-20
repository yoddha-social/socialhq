<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Ask the account owner to connect": a link an admin sends to someone who owns
 * a network account but has no Social HQ login. One row per link, kept as the
 * record of who asked, when it was used and what it connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_connect_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 40);
            // Set when the link is for a channel that lost its connection: only that account may be picked.
            $table->uuid('reconnect_account_id')->nullable();
            // The link holds the token; only its SHA-256 is stored.
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->json('connected')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_connect_invites');
    }
};
