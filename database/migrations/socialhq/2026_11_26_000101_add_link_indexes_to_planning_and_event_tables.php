<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes on the columns that link plan items, briefs, item posts and event
 * flags and hides to channels, users and workspaces (audit 2026-11-24 BE-16).
 * PostgreSQL doesn't index a foreign key by itself, so disconnecting a
 * channel, deleting a user or a workspace, and the disconnect warning's count
 * of affected items read the whole table. Each index is added only when no
 * index on exactly those columns exists yet.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<string>}> */
    private const INDEXES = [
        'shq_plan_items_social_account_id_index' => ['shq_plan_items', ['social_account_id']],
        'shq_plan_items_owner_id_index' => ['shq_plan_items', ['owner_id']],
        'shq_plan_items_created_by_index' => ['shq_plan_items', ['created_by']],
        'shq_briefs_approved_by_index' => ['shq_briefs', ['approved_by']],
        'shq_briefs_created_by_index' => ['shq_briefs', ['created_by']],
        'shq_plan_item_posts_workspace_id_index' => ['shq_plan_item_posts', ['workspace_id']],
        'shq_plan_item_posts_linked_by_index' => ['shq_plan_item_posts', ['linked_by']],
        'shq_event_hides_hidden_by_index' => ['shq_event_hides', ['hidden_by']],
        'shq_event_flags_user_id_index' => ['shq_event_flags', ['user_id']],
        'shq_event_flags_resolved_by_index' => ['shq_event_flags', ['resolved_by']],
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
