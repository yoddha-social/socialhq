<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

/**
 * The text read from a knowledge document, cleaned up (DocumentText).
 */
final readonly class ExtractedText
{
    /**
     * @param  int|null  $pages  Pages of a PDF, slides of a deck; null for other files
     * @param  bool  $truncated  Longer than we keep: the rest was dropped
     */
    public function __construct(
        public string $text,
        public ?int $pages,
        public bool $truncated,
    ) {}
}
