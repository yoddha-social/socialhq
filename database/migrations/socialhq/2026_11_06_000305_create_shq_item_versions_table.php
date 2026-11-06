<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_item_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('shq_plan_items')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->text('caption');
            $table->string('hashtags', 500)->nullable();
            $table->text('script')->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 10)->default('manual');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['item_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_item_versions');
    }
};
