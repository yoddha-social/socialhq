<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
