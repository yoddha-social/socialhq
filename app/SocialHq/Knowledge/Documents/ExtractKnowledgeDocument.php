<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads an uploaded knowledge document into excerpts, once. Ends with the
 * document ready (excerpts saved) or failed with a reason the page shows;
 * a crash, a timeout or running out of memory counts as unreadable.
 */
class ExtractKnowledgeDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $documentId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document === null || $document->status !== DocumentStatus::Processing) {
            return;
        }

        $type = $document->type();
        $disk = DocumentStorage::disk();

        if ($type === null || ! $disk->exists($document->path)) {
            self::markFailed($document, DocumentFailure::Unreadable);

            return;
        }

        try {
            $extracted = DocumentText::extract($disk->path($document->path), $type);
        } catch (DocumentUnreadable $unreadable) {
            self::markFailed($document, $unreadable->failure);

            return;
        }

        self::store($document, $extracted);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Knowledge document could not be read', [
            'document_id' => $this->documentId,
            'exception' => $exception === null ? null : $exception::class,
        ]);

        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document !== null && $document->status === DocumentStatus::Processing) {
            self::markFailed($document, DocumentFailure::Unreadable);
        }
    }

    /**
     * Saves the text's excerpts and marks the document ready, with any other
     * columns given, in one transaction. False when the document was deleted
     * meanwhile (nothing is written).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function store(KnowledgeDocument $document, ExtractedText $extracted, array $attributes = []): bool
    {
        $chunks = Chunker::split($extracted->text);
        $now = now();

        return DB::transaction(function () use ($document, $chunks, $extracted, $attributes, $now): bool {
            if (! KnowledgeDocument::query()->whereKey($document->id)->lockForUpdate()->exists()) {
                return false;
            }

            KnowledgeChunk::query()->where('document_id', $document->id)->delete();

            foreach (array_chunk($chunks, 100, preserve_keys: true) as $batch) {
                KnowledgeChunk::query()->insert(array_map(fn (string $text, int $position): array => [
                    'document_id' => $document->id,
                    'workspace_id' => $document->workspace_id,
                    'position' => $position,
                    'text' => $text,
                    'terms' => json_encode(KnowledgeChunk::termsOf($text), JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $batch, array_keys($batch)));
            }

            $document->forceFill([
                ...$attributes,
                'status' => DocumentStatus::Ready,
                'failure' => null,
                'pages' => $extracted->pages,
                'characters' => mb_strlen($extracted->text),
                'truncated' => $extracted->truncated,
                'chunk_count' => count($chunks),
            ])->save();

            return true;
        });
    }

    /**
     * The document failed: its reason is kept and any excerpts of an earlier
     * text go, so nothing it no longer holds reaches the AI.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function markFailed(KnowledgeDocument $document, DocumentFailure $failure, array $attributes = []): void
    {
        DB::transaction(function () use ($document, $failure, $attributes): void {
            KnowledgeChunk::query()->where('document_id', $document->id)->delete();
            $document->forceFill([...$attributes, 'status' => DocumentStatus::Failed, 'failure' => $failure, 'chunk_count' => 0, 'characters' => 0])->save();
        });
    }
}
