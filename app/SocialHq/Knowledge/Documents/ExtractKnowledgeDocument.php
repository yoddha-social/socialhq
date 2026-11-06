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
            $this->markFailed($document, DocumentFailure::Unreadable);

            return;
        }

        try {
            $extracted = DocumentText::extract($disk->path($document->path), $type);
        } catch (DocumentUnreadable $unreadable) {
            $this->markFailed($document, $unreadable->failure);

            return;
        }

        $chunks = Chunker::split($extracted->text);
        $now = now();

        DB::transaction(function () use ($document, $chunks, $extracted, $now): void {
            KnowledgeChunk::query()->where('document_id', $document->id)->delete();

            foreach (array_chunk($chunks, 100, preserve_keys: true) as $batch) {
                KnowledgeChunk::query()->insert(array_map(fn (string $text, int $position): array => [
                    'document_id' => $document->id,
                    'workspace_id' => $document->workspace_id,
                    'position' => $position,
                    'text' => $text,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $batch, array_keys($batch)));
            }

            $document->forceFill([
                'status' => DocumentStatus::Ready,
                'failure' => null,
                'pages' => $extracted->pages,
                'characters' => mb_strlen($extracted->text),
                'truncated' => $extracted->truncated,
                'chunk_count' => count($chunks),
            ])->save();
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Knowledge document could not be read', [
            'document_id' => $this->documentId,
            'exception' => $exception === null ? null : $exception::class,
        ]);

        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document !== null && $document->status === DocumentStatus::Processing) {
            $this->markFailed($document, DocumentFailure::Unreadable);
        }
    }

    private function markFailed(KnowledgeDocument $document, DocumentFailure $failure): void
    {
        $document->forceFill(['status' => DocumentStatus::Failed, 'failure' => $failure, 'chunk_count' => 0, 'characters' => 0])->save();
    }
}
