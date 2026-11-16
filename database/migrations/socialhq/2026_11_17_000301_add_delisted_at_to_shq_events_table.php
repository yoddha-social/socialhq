<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an imported date left its source's list (the official list or
 * Nager.Date), for example because the source corrected its date or name:
 * it is flagged, needs a check again and is kept out of planning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shq_events', 'delisted_at')) {
            Schema::table('shq_events', fn (Blueprint $t) => $t->timestamp('delisted_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shq_events', 'delisted_at')) {
            Schema::table('shq_events', fn (Blueprint $t) => $t->dropColumn('delisted_at'));
        }
    }
};
