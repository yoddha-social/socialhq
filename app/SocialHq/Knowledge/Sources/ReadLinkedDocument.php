<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use App\SocialHq\Knowledge\Documents\DocumentFailure;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\DocumentText;
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
 * When reading again fails, the excerpts read before stay too.
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
            $this->fail($document, DocumentFailure::NotFound);

            return;
        }

        try {
            $read = GoogleExport::read($link);
        } catch (DocumentUnreadable $unreadable) {
            $this->fail($document, $unreadable->failure);

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

        // Read and saved here, so a crash on the way counts as unreadable, not as Google out of reach.
        try {
            $extracted = DocumentText::extract($disk->path($path), DocumentType::Text);
            $stored = ExtractKnowledgeDocument::store($document, $extracted, [
                'title' => $title,
                'file_name' => DocumentStorage::fileName($title, 'txt'),
                'mime' => DocumentType::Text->mime(),
                'size' => strlen($read['text']),
                'path' => $path,
                'source_hash' => $hash,
                'read_at' => now(),
            ]);
        } catch (DocumentUnreadable $unreadable) {
            $disk->delete($path);
            $this->fail($document, $unreadable->failure);

            return;
        } catch (Throwable $crash) {
            $disk->delete($path);
            report($crash);
            $this->fail($document->refresh(), DocumentFailure::Unreadable);

            return;
        }

        // Deleted while it was read: the new file has no row to go with.
        if (! $stored) {
            $disk->delete($path);

            return;
        }

        if (filled($previous) && $previous !== $path) {
            $disk->delete($previous);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Linked knowledge document could not be read', [
            'document_id' => $this->documentId,
            'exception' => $exception === null ? null : $exception::class,
        ]);

        $document = KnowledgeDocument::query()->find($this->documentId);

        if ($document !== null && $document->status === DocumentStatus::Processing) {
            $this->fail($document, DocumentFailure::LinkUnreachable);
        }
    }

    /**
     * A first read that fails leaves the document failed. "Read again" that
     * fails keeps it ready with what was read before, so the AI goes on using
     * it, and records why the last read failed (`failure` on a ready document).
     */
    private function fail(KnowledgeDocument $document, DocumentFailure $failure): void
    {
        if ($document->chunk_count > 0) {
            $document->forceFill(['status' => DocumentStatus::Ready, 'failure' => $failure, 'read_at' => now()])->save();

            return;
        }

        ExtractKnowledgeDocument::markFailed($document, $failure, ['read_at' => now()]);
    }
}
