<?php

declare(strict_types=1);

namespace App\SocialHq\AiGraphics;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The vision check of AI graphics: looks at a request (prompt and reference
 * images) or at a generated graphic and says whether a company may post it.
 */
class GraphicSafetyReviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(public bool $reviewsResult = false) {}

    public function instructions(): string
    {
        $subject = $this->reviewsResult
            ? 'a graphic an image model just generated for a company\'s social media post. You see the request\'s prompt and the generated image.'
            : 'a request to generate a graphic for a company\'s social media post: a text prompt and, possibly, reference images the generated graphic should use or follow.';

        return <<<PROMPT
        You review {$subject}

        Block it (allowed: false) when any of these applies:
        - sexual content or nudity, or anything sexualising minors;
        - graphic violence, gore, self-harm, or cruelty;
        - hate, harassment, or extremist symbols;
        - a real, identifiable person (in a reference photo or by name) shown in a way that could mislead or harm them: changed face or body, a fake endorsement, a fake statement, an embarrassing or false situation;
        - another company's logo, brand, product design, or a copyrighted character presented as the company's own, or a request to copy someone else's artwork or remove a watermark;
        - fake documents, IDs, money, official seals, news screenshots, or anything made to deceive;
        - weapons, drugs, or other illegal goods promoted, or misleading health, financial, or political claims.

        Allow ordinary marketing graphics: products, people in normal situations (including the company's own team in reference photos), places, festivals, text on images, illustrations, and edits of the company's own photos.
        Judge only what is in front of you; do not block for vague possibilities. When you block, give a short reason the requester can act on, in plain English, under 200 characters. When you allow, leave the reason empty.
        PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'allowed' => $schema->boolean()->required(),
            'reason' => $schema->string()->required(),
        ];
    }
}
