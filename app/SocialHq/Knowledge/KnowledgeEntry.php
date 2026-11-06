<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing the team wants the AI to know about a brand: a product, a
 * project, a past campaign, a fact.
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
}
