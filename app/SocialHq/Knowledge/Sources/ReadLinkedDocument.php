<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use App\SocialHq\Knowledge\Documents\DocumentFailure;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\DocumentType;
use App\SocialHq\Knowledge\Documents\DocumentUnreadable;
use App\SocialHq\Knowledge\Documents\ExtractKnowledgeDocument;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a Google Docs, Sheets or Slides link (added, or "Read again") into
 * a knowledge document: the text is stored as a .txt file on the private
 * disk and goes through the same extraction as an upload. When the text
 * is the same as last time, the excerpts stay and nothing is read again.
 */
class ReadLinkedDocument implements ShouldQueue
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

        if ($document === null || $document->status !== DocumentStatus::Processing || ! $document->source->isGoogle()) {
            return;
        }

        $link = GoogleLink::parse((string) $document->source_url);

        if ($link === null) {
            $this->markFailed($document, DocumentFailure::NotFound);

            return;
        }

        try {
            $read = GoogleExport::read($link);
        } catch (DocumentUnreadable $unreadable) {
            $this->markFailed($document, $unreadable->failure);

            return;
        }

        $hash = hash('sha256', $read['text']);
        $disk = DocumentStorage::disk();
        $title = $document->auto_title && $read['name'] !== null ? $read['name'] : $document->title;

        if ($hash === $document->source_hash && $document->chunk_count > 0 && filled($document->path) && $disk->exists($document->path)) {
            $document->forceFill(['status' => DocumentStatus::Ready, 'failure' => null, 'title' => $title, 'read_at' => now()])->save();

            return;
        }

        $previous = $document->path;
        $path = DocumentStorage::directory((string) $document->workspace_id).'/'.Str::uuid()->toString().'.txt';
        $disk->put($path, $read['text']);

        $document->forceFill([
            'title' => $title,
            'file_name' => Str::limit($title, 240, '').'.txt',
            'mime' => DocumentType::Text->mime(),
            'size' => strlen($read['text']),
            'path' => $path,
            'source_hash' => $hash,
            'read_at' => now(),
        ])->save();

        if (filled($previous) && $previous !== $path) {
            $disk->delete($previous);
        }

        (new ExtractKnowledgeDocument($document->id))->handle();
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Linked knowledge document could not be read', [
            'document_id' => $this->documentId,
            'exception' => $exception === null ? null : $exception::class,
        ]);

        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document !== null && $document->status === DocumentStatus::Processing) {
            $this->markFailed($document, DocumentFailure::LinkUnreachable);
        }
    }

    private function markFailed(KnowledgeDocument $document, DocumentFailure $failure): void
    {
        $document->forceFill(['status' => DocumentStatus::Failed, 'failure' => $failure, 'chunk_count' => 0, 'characters' => 0, 'read_at' => now()])->save();
    }
}
