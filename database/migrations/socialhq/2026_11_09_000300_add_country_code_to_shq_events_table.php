<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The country an event is observed in (ISO 3166-1 alpha-2): NP for Nepal's
 * dates, a country's code for its public holidays, null for international
 * days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shq_events', 'country_code')) {
            Schema::table('shq_events', fn (Blueprint $t) => $t->string('country_code', 2)->nullable()->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shq_events', 'country_code')) {
            Schema::table('shq_events', fn (Blueprint $t) => $t->dropColumn('country_code'));
        }
    }
};
