<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An approver's "post it text-only": the item's post no longer waits for its
 * creative. Who allowed it and when, so the item page can say so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shq_plan_items', 'creative_waived_at')) {
            Schema::table('shq_plan_items', function (Blueprint $table): void {
                $table->timestamp('creative_waived_at')->nullable();
                $table->foreignUuid('creative_waived_by')->nullable()->index()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shq_plan_items', 'creative_waived_at')) {
            Schema::table('shq_plan_items', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('creative_waived_by');
                $table->dropColumn('creative_waived_at');
            });
        }
    }
};
