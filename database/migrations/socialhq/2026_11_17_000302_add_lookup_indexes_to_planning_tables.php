<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the planning layer's frequent lookups: a creative round's
 * review steps, a copy version's reviews, a designer's briefs, an event's
 * items and a brand's own dates. Foreign keys get an index of their own on
 * MySQL but not on PostgreSQL, so each one is added only when no index on
 * exactly those columns exists yet.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<string>}> */
    private const INDEXES = [
        'shq_item_reviews_brief_round_kind_index' => ['shq_item_reviews', ['brief_id', 'round', 'kind']],
        'shq_item_reviews_version_id_index' => ['shq_item_reviews', ['version_id']],
        'shq_briefs_assignee_id_index' => ['shq_briefs', ['assignee_id']],
        'shq_plan_items_event_id_index' => ['shq_plan_items', ['event_id']],
        'shq_events_workspace_id_index' => ['shq_events', ['workspace_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => [$table, $columns]) {
            if (! Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $name => [$table]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
