<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\Workspace;
use App\SocialHq\Knowledge\Search\Tokens;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the team wants the AI to know about a brand: a product, a
 * project, a past campaign, a fact. Pinned entries go into prompts first.
 * `terms` holds search's word counts (the title three times the body),
 * written whenever the text changes.
 */
class KnowledgeEntry extends Model
{
    protected $table = 'shq_knowledge_entries';

    /** A title word counts this many times a body word in search. */
    public const int TITLE_WEIGHT = 3;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => KnowledgeKind::class,
            'use_in_ai' => 'boolean',
            'pinned' => 'boolean',
            'terms' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (KnowledgeEntry $entry): void {
            if (! $entry->exists || $entry->isDirty(['title', 'body'])) {
                $entry->terms = $entry->searchTerms();
            }
        });
    }

    /**
     * Search's word counts of the title and body.
     *
     * @return array<string, int>
     */
    public function searchTerms(): array
    {
        return Tokens::add(Tokens::frequencies((string) $this->title, self::TITLE_WEIGHT), Tokens::frequencies((string) $this->body));
    }

    /**
     * Counts for entries written before they were stored, filled once.
     */
    public static function fillMissingTerms(string $workspaceId): void
    {
        self::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('terms')
            ->lazyById(200)
            ->each(function (KnowledgeEntry $entry): void {
                // Not an edit: updated_at stays.
                $entry->timestamps = false;
                $entry->forceFill(['terms' => $entry->searchTerms()])->saveQuietly();
            });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<KnowledgeEntry>  $query
     */
    public function scopeUsedInAi(Builder $query): void
    {
        $query->where('use_in_ai', true);
    }

    /**
     * The order entries reach the prompt: pinned first, then newest. Not by
     * updated_at, so fixing a typo never pushes other entries out.
     *
     * @param  Builder<KnowledgeEntry>  $query
     */
    public function scopeInPromptOrder(Builder $query): void
    {
        $query->orderByDesc('pinned')->latest('created_at')->latest('id');
    }
}
