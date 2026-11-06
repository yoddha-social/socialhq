<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shq_ai_graphics', function (Blueprint $table) {
            // Our own copy of the result on the local disk, so a graphic can be
            // attached again after its first upload was used or cleaned up.
            $table->string('result_path')->nullable()->after('media_id');
        });
    }

    public function down(): void
    {
        Schema::table('shq_ai_graphics', function (Blueprint $table) {
            $table->dropColumn('result_path');
        });
    }
};
