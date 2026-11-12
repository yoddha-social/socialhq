<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use App\Models\Workspace;

/**
 * The `documents` prop of the Brand knowledge page: the workspace's
 * documents, newest first, and the upload limits (`counted` leaves out the
 * website's text, which doesn't count toward them).
 */
final class DocumentsPage
{
    /**
     * @return array{items: list<array<string, mixed>>, limit: int, counted: int, maxMegabytes: int}
     */
    public static function prop(Workspace $workspace): array
    {
        return [
            'items' => KnowledgeDocument::query()
                ->where('workspace_id', $workspace->id)
                ->latest('created_at')
                ->latest('id')
                ->get()
                ->map(fn (KnowledgeDocument $document): array => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'file_name' => $document->file_name,
                    'type' => $document->type()?->value,
                    'size' => $document->size,
                    'pages' => $document->pages,
                    'characters' => $document->characters,
                    'truncated' => $document->truncated,
                    'chunk_count' => $document->chunk_count,
                    'status' => $document->status->value,
                    'failure' => $document->failure?->value,
                    'use_in_ai' => $document->use_in_ai,
                    'source' => $document->source->value,
                    'source_url' => $document->source->isGoogle() ? $document->source_url : null,
                    'read_at' => $document->read_at?->toIso8601String(),
                    'created_at' => $document->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'limit' => self::limit(),
            'counted' => KnowledgeDocument::query()->where('workspace_id', $workspace->id)->countedTowardLimit()->count(),
            'maxMegabytes' => intdiv(max(1, (int) config('socialhq.knowledge.documents.max_kilobytes', 10240)), 1024),
        ];
    }

    /**
     * Whether the workspace may add another upload or link.
     */
    public static function hasRoom(Workspace $workspace): bool
    {
        return KnowledgeDocument::query()->where('workspace_id', $workspace->id)->countedTowardLimit()->count() < self::limit();
    }

    public static function limit(): int
    {
        return max(1, (int) config('socialhq.knowledge.documents.max_documents', 30));
    }
}
