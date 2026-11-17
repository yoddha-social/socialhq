<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * shq_ai_graphics.media_id points at the upload (medias) a graphic was first stored
 * as. Uploads are deleted on their own (with a post, an idea, or as loose
 * uploads), so the key only forgets the upload (null on delete). Rows whose
 * upload is already gone are cleared first, or the key can't be added.
 */
return new class extends Migration
{
    private const FOREIGN_KEY = 'shq_ai_graphics_media_id_foreign';

    public function up(): void
    {
        if ($this->hasForeignKey()) {
            return;
        }

        DB::table('shq_ai_graphics')
            ->whereNotNull('media_id')
            ->whereNotExists(fn (Builder $query) => $query->from('medias')->whereColumn('medias.id', 'shq_ai_graphics.media_id'))
            ->update(['media_id' => null]);

        Schema::table('shq_ai_graphics', fn (Blueprint $table) => $table->foreign('media_id', self::FOREIGN_KEY)->references('id')->on('medias')->nullOnDelete());
    }

    public function down(): void
    {
        if ($this->hasForeignKey()) {
            Schema::table('shq_ai_graphics', fn (Blueprint $table) => $table->dropForeign(self::FOREIGN_KEY));
        }
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('shq_ai_graphics'))->contains(fn (array $key): bool => $key['name'] === self::FOREIGN_KEY);
    }
};
