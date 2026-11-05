<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_access_requests', function (Blueprint $table) {
            // How a request was closed without an admin's decision (joined_by_invite).
            $table->string('note', 40)->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('shq_access_requests', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
