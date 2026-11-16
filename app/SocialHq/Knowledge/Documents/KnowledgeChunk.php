<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use App\SocialHq\Knowledge\Search\Tokens;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One excerpt of a knowledge document (about 900 characters), in document
 * order by position. Rows go with their document (cascade). `terms` holds
 * search's word counts of the text (Tokens), written with it.
 */
class KnowledgeChunk extends Model
{
    protected $table = 'shq_knowledge_chunks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'terms' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (KnowledgeChunk $chunk): void {
            if (! $chunk->exists || $chunk->isDirty('text')) {
                $chunk->terms = self::termsOf((string) $chunk->text);
            }
        });
    }

    /**
     * Search's word counts of an excerpt's text.
     *
     * @return array<string, int>
     */
    public static function termsOf(string $text): array
    {
        return Tokens::frequencies($text);
    }

    /**
     * Counts for excerpts written before they were stored, filled once.
     */
    public static function fillMissingTerms(string $workspaceId): void
    {
        self::query()
            ->where('workspace_id', $workspaceId)
            ->whereNull('terms')
            ->lazyById(200)
            ->each(function (KnowledgeChunk $chunk): void {
                // Not an edit: updated_at stays.
                $chunk->timestamps = false;
                $chunk->forceFill(['terms' => self::termsOf((string) $chunk->text)])->saveQuietly();
            });
    }

    /**
     * @return BelongsTo<KnowledgeDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'document_id');
    }
}
