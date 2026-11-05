<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the team wants the AI to know about a brand: a product, a
 * project, a past campaign, a fact. Pinned entries go into prompts first.
 */
class KnowledgeEntry extends Model
{
    protected $table = 'shq_knowledge_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => KnowledgeKind::class,
            'use_in_ai' => 'boolean',
            'pinned' => 'boolean',
        ];
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
