<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Names, numbers and quotes in an AI-written version that its sources (the
 * item, its event, the brand knowledge, the team's request) don't contain:
 * the reviewer checks them before approving.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shq_item_versions', 'unsupported_details')) {
            Schema::table('shq_item_versions', fn (Blueprint $table) => $table->json('unsupported_details')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shq_item_versions', 'unsupported_details')) {
            Schema::table('shq_item_versions', fn (Blueprint $table) => $table->dropColumn('unsupported_details'));
        }
    }
};
