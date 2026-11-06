<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_brand_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('about')->nullable();
            $table->text('audience')->nullable();
            $table->text('voice')->nullable();
            $table->text('words_to_use')->nullable();
            $table->text('words_to_avoid')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_brand_profiles');
    }
};
