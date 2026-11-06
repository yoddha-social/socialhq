<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Search;

/**
 * One search hit: a knowledge entry or a document excerpt, by id, with its
 * score (only comparable within one search).
 */
final readonly class KnowledgeMatch
{
    public const string ENTRY = 'entry';

    public const string CHUNK = 'chunk';

    /**
     * @param  self::ENTRY|self::CHUNK  $kind
     */
    public function __construct(
        public string $kind,
        public int $id,
        public float $score,
    ) {}

    public function isEntry(): bool
    {
        return $this->kind === self::ENTRY;
    }
}
