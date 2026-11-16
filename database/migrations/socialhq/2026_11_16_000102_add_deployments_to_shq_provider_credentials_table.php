<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What LiteLLM holds for each key: per task, a fingerprint of the
     * deployment the app added. A change then removes and adds only the
     * deployments that differ. Null: not known (keys added before this
     * column), so the next change removes and adds all of them once.
     */
    public function up(): void
    {
        Schema::table('shq_provider_credentials', function (Blueprint $table) {
            $table->json('deployments')->nullable()->after('models');
        });
    }

    public function down(): void
    {
        Schema::table('shq_provider_credentials', function (Blueprint $table) {
            $table->dropColumn('deployments');
        });
    }
};
