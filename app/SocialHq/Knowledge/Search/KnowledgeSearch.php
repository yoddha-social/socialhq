<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Search;

use App\Models\Workspace;

/**
 * Finds the brand knowledge that matches a request: knowledge entries marked
 * for AI and excerpts of ready documents marked for AI, best first.
 *
 * Bound to LexicalKnowledgeSearch (BM25 over words). An embeddings search
 * would implement this same interface; BrandKnowledge only reads the ranked
 * matches, so it needs no change.
 */
interface KnowledgeSearch
{
    /**
     * Matches for $focus in the workspace, best first. Only items with a
     * positive score; none when nothing matches.
     *
     * @return list<KnowledgeMatch>
     */
    public function search(Workspace $workspace, string $focus): array;
}
