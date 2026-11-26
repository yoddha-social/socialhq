<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A connect link sent to the wrong person can be cancelled (audit 2026-11-24,
 * SEC-17). Cancelling stops it like a use does (`used_at`); these record that
 * it was cancelled, when and by whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_connect_invites', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('used_ip');
            $table->foreignUuid('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shq_connect_invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });
    }
};
