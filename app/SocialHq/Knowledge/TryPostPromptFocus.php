<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge;

/**
 * The focus for brand knowledge in TryPost's own prompts (the caption
 * assistant and the idea generator), read from the data their prompt views
 * already hold, so no TryPost file changes:
 *
 * - the idea generator: the business and the notes typed in its form;
 * - the caption assistant: the existing caption, and for a new caption the
 *   request typed in the composer (its `prompt` field, which only reaches
 *   the agent as the user message, not as view data).
 *
 * The focus only ranks knowledge; it is never put in the prompt by us.
 */
final class TryPostPromptFocus
{
    private const MAX_CHARACTERS = 2000;

    /**
     * @param  array<string, mixed>  $data  The prompt view's data
     */
    public static function of(array $data): string
    {
        $parts = [
            data_get($data, 'business'),
            data_get($data, 'notes'),
            data_get($data, 'current_content'),
        ];

        // Only the caption assistant's view has writes_new_text; its request is the HTTP request's prompt.
        if (data_get($data, 'writes_new_text') === true && app()->bound('request')) {
            $parts[] = request()->input('prompt');
        }

        $text = collect($parts)
            ->filter(fn (mixed $part): bool => is_string($part) && filled($part))
            ->map(fn (string $part): string => trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], "\n", $part)))))
            ->implode("\n");

        return mb_substr($text, 0, self::MAX_CHARACTERS);
    }
}
