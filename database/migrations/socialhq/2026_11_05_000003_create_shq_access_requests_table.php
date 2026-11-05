<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_access_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            // Null once a declined request's user is deleted; the row stays as history.
            $table->foreignUuid('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('status', 10)->default('pending');
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('admins_notified_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_access_requests');
    }
};
