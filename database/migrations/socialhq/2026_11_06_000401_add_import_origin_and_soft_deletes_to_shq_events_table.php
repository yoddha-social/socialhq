<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imported events remember which row of the official list they came from
 * (import_key + import_date), so a re-import finds them even after the team
 * moved, renamed or narrowed them to one brand. Deleting an event only hides
 * it (deleted_at), so a re-import doesn't bring it back.
 *
 * The (account, workspace, key, starts_on) unique index goes: it never held for
 * account-wide rows (workspace_id null) and would block re-adding a date that
 * was deleted. The app checks for duplicates under a lock instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_events', function (Blueprint $table) {
            $table->string('import_key', 80)->nullable()->after('key');
            $table->date('import_date')->nullable()->after('import_key');
            $table->softDeletes();

            $table->index(['account_id', 'import_key', 'import_date']);
        });

        // Rows the importer created so far: no person created them.
        $grammar = DB::connection()->getQueryGrammar();
        DB::table('shq_events')
            ->whereNull('created_by')
            ->update([
                'import_key' => DB::raw($grammar->wrap('key')),
                'import_date' => DB::raw($grammar->wrap('starts_on')),
            ]);

        Schema::table('shq_events', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'workspace_id', 'key', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::table('shq_events', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'import_key', 'import_date']);
            $table->dropColumn(['import_key', 'import_date', 'deleted_at']);
            $table->unique(['account_id', 'workspace_id', 'key', 'starts_on']);
        });
    }
};
