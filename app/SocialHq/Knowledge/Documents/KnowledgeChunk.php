<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One excerpt of a knowledge document (about 900 characters), in document
 * order by position. Rows go with their document (cascade).
 */
class KnowledgeChunk extends Model
{
    protected $table = 'shq_knowledge_chunks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
