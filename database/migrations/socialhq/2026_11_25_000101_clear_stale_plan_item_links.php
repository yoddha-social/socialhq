<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Two links on plan items that outlived what they pointed at (audit
 * 2026-11-24, BE-11 and FEAT-05):
 *
 * - `post_created_at` was set when the composer opened (before posts were
 *   linked to items, 2026-11-23) and never cleared when the post went.
 *   "Has a post" now reads the linked posts; the stamps of items with no
 *   linked post are cleared, so the stamp only means "first post saved on".
 * - `event_id` kept pointing at deleted dates. Deleting a date now clears
 *   it from items; this clears the ones deleted before.
 *
 * Running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shq_plan_items')
            ->whereNotNull('post_created_at')
            ->whereNotExists(fn (Builder $links) => $links->select('id')
                ->from('shq_plan_item_posts')
                ->whereColumn('shq_plan_item_posts.plan_item_id', 'shq_plan_items.id'))
            ->update(['post_created_at' => null]);

        DB::table('shq_plan_items')
            ->whereIn('event_id', fn (Builder $events) => $events->select('id')
                ->from('shq_events')
                ->whereNotNull('deleted_at'))
            ->update(['event_id' => null]);
    }

    /**
     * The cleared links pointed at nothing; there is nothing to put back.
     */
    public function down(): void
    {
        //
    }
};
