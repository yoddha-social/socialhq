<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_companies', function (Blueprint $table) {
            // When the owner asked (again, after a decline), and when platform
            // admins were emailed about it (once the owner's email is confirmed).
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('admins_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shq_companies', function (Blueprint $table) {
            $table->dropColumn(['requested_at', 'admins_notified_at']);
        });
    }
};
