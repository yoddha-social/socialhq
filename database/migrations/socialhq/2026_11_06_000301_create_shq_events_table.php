<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            // Null: every brand of the account. Set: a date for one brand only.
            $table->foreignUuid('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name', 160);
            $table->string('name_ne', 160)->nullable();
            $table->string('category', 30);
            $table->string('region', 20);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('bs_date', 60)->nullable();
            $table->boolean('public_holiday')->default(false);
            $table->text('description')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('source_name', 160)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'workspace_id', 'key', 'starts_on']);
            $table->index(['account_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_events');
    }
};
