<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A file a workspace admin uploaded for brand knowledge (a brochure, a price
 * list, a deck). Its text is read once (ExtractKnowledgeDocument) into
 * excerpts (KnowledgeChunk) that search finds for the request at hand. The
 * file stays on the private disk, for download only.
 */
class KnowledgeDocument extends Model
{
    protected $table = 'shq_knowledge_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'failure' => DocumentFailure::class,
            'use_in_ai' => 'boolean',
            'truncated' => 'boolean',
            'size' => 'integer',
            'pages' => 'integer',
            'characters' => 'integer',
            'chunk_count' => 'integer',
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
     * @return HasMany<KnowledgeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'document_id');
    }

    public function type(): ?DocumentType
    {
        return DocumentType::fromMime($this->mime);
    }

    /**
     * Documents whose excerpts search may give the AI: read, and marked "Use in AI writing".
     *
     * @param  Builder<KnowledgeDocument>  $query
     */
    public function scopeSearchable(Builder $query): void
    {
        $query->where('status', DocumentStatus::Ready)->where('use_in_ai', true);
    }
}
