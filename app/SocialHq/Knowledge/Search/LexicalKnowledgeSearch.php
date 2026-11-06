<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Search;

use App\Models\Workspace;
use App\SocialHq\Knowledge\Documents\KnowledgeChunk;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\KnowledgeEntry;

/**
 * BM25 ranking over words, done in PHP: a workspace's corpus is small (at
 * most a few hundred entries and 30 documents), and it works the same on
 * PostgreSQL and MySQL. An entry's title counts three times its body; an
 * excerpt's document title counts once, next to its text.
 *
 * It only finds the words of the request (after Tokens: lowercase, no
 * stopwords, plural "s" dropped), not their meaning: "cost" does not find
 * "price", and English does not find Nepali. Embeddings would add that:
 * store a vector per entry and excerpt (pgvector on PostgreSQL, through the
 * `embed` AI task, refreshed when an entry is saved or a document read),
 * embed the focus, rank by cosine similarity, and blend with these scores
 * (reciprocal rank fusion) so exact names and prices still win. Bind that
 * class to KnowledgeSearch in SocialHqServiceProvider; MySQL would keep this
 * one.
 */
final class LexicalKnowledgeSearch implements KnowledgeSearch
{
    private const K1 = 1.2;

    private const B = 0.75;

    private const ENTRY_TITLE_WEIGHT = 3;

    public function search(Workspace $workspace, string $focus): array
    {
        $query = array_values(array_unique(Tokens::of($focus)));

        if ($query === []) {
            return [];
        }

        /** @var list<array{kind: string, id: int, created: int, terms: array<string, int>, length: int}> $items */
        $items = [];

        $entries = KnowledgeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->usedInAi()
            ->get(['id', 'title', 'body', 'created_at']);

        foreach ($entries as $entry) {
            $items[] = self::item(KnowledgeMatch::ENTRY, $entry->id, (int) $entry->created_at?->getTimestamp(), [
                [$entry->title, self::ENTRY_TITLE_WEIGHT],
                [$entry->body, 1],
            ]);
        }

        $chunks = KnowledgeChunk::query()
            ->where('shq_knowledge_chunks.workspace_id', $workspace->id)
            ->whereIn('document_id', KnowledgeDocument::query()->where('workspace_id', $workspace->id)->searchable()->select('id'))
            ->join('shq_knowledge_documents', 'shq_knowledge_documents.id', '=', 'shq_knowledge_chunks.document_id')
            ->orderBy('shq_knowledge_chunks.id')
            ->get(['shq_knowledge_chunks.id', 'shq_knowledge_chunks.text', 'shq_knowledge_documents.title as document_title', 'shq_knowledge_chunks.created_at']);

        foreach ($chunks as $chunk) {
            $items[] = self::item(KnowledgeMatch::CHUNK, $chunk->id, (int) $chunk->created_at?->getTimestamp(), [
                [(string) $chunk->getAttribute('document_title'), 1],
                [$chunk->text, 1],
            ]);
        }

        if ($items === []) {
            return [];
        }

        $count = count($items);
        $averageLength = max(1, array_sum(array_column($items, 'length')) / $count);
        $idf = [];

        foreach ($query as $term) {
            $frequency = 0;

            foreach ($items as $item) {
                if (isset($item['terms'][$term])) {
                    $frequency++;
                }
            }

            $idf[$term] = log(1 + ($count - $frequency + 0.5) / ($frequency + 0.5));
        }

        $matches = [];

        foreach ($items as $item) {
            $score = 0.0;

            foreach ($query as $term) {
                $tf = $item['terms'][$term] ?? 0;

                if ($tf === 0) {
                    continue;
                }

                $score += $idf[$term] * ($tf * (self::K1 + 1)) / ($tf + self::K1 * (1 - self::B + self::B * $item['length'] / $averageLength));
            }

            if ($score > 0) {
                $matches[] = [$item, $score];
            }
        }

        // Best first; on a tie entries before excerpts, then the newest.
        $order = fn (array $match): array => [$match[1], $match[0]['kind'] === KnowledgeMatch::ENTRY ? 1 : 0, $match[0]['created'], $match[0]['id']];
        usort($matches, fn (array $left, array $right): int => $order($right) <=> $order($left));

        return array_map(fn (array $match): KnowledgeMatch => new KnowledgeMatch($match[0]['kind'], $match[0]['id'], round($match[1], 6)), $matches);
    }

    /**
     * Term frequencies of an item's weighted fields.
     *
     * @param  list<array{0: string, 1: int}>  $fields
     * @return array{kind: string, id: int, created: int, terms: array<string, int>, length: int}
     */
    private static function item(string $kind, int $id, int $created, array $fields): array
    {
        $terms = [];
        $length = 0;

        foreach ($fields as [$text, $weight]) {
            foreach (Tokens::of($text) as $token) {
                $terms[$token] = ($terms[$token] ?? 0) + $weight;
                $length += $weight;
            }
        }

        return ['kind' => $kind, 'id' => $id, 'created' => $created, 'terms' => $terms, 'length' => $length];
    }
}
