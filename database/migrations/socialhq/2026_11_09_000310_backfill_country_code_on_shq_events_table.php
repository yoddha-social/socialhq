<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dates of the Nepal region are Nepal's (NP); international days keep no
 * country. A brand's own dates keep none either: nothing says which country
 * they belong to.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shq_events')
            ->where('region', 'nepal')
            ->whereNull('country_code')
            ->update(['country_code' => 'NP']);
    }

    public function down(): void
    {
        // The column itself goes with 2026_11_09_000300: nothing to undo here.
    }
};
