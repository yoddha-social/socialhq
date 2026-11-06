<?php

declare(strict_types=1);

use App\SocialHq\Knowledge\Calendars\CalendarImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shq_calendar_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('file_type', 10);
            // The uploaded file on the private disk, until the import is confirmed.
            $table->string('path', 500)->nullable();
            $table->string('status', 20)->default(CalendarImportStatus::DEFAULT->value);
            $table->unsignedSmallInteger('sheet')->nullable();
            $table->json('column_mapping')->nullable();
            $table->string('date_order', 20)->nullable();
            $table->unsignedInteger('rows_imported')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->json('skipped_reasons')->nullable();
            $table->date('first_date')->nullable();
            $table->date('last_date')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shq_calendar_imports');
    }
};
