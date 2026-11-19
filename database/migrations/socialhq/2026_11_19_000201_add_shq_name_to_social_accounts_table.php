<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Name in Social HQ": what a team calls a channel, shown in place of the name
 * the network reports. The network's own name stays in `display_name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('social_accounts', 'shq_name')) {
            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->string('shq_name', 60)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('social_accounts', 'shq_name')) {
            Schema::table('social_accounts', function (Blueprint $table): void {
                $table->dropColumn('shq_name');
            });
        }
    }
};
