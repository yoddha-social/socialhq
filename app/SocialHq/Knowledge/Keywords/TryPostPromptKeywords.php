<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\Models\Workspace;

/**
 * The SEO keywords in TryPost's own prompts (the caption assistant and the
 * idea generator), read like TryPostPromptFocus from what their views and
 * the request already hold:
 *
 * - a rewrite of the person's caption (shorten, rephrase, tone) is only
 *   told to keep the catalogue keywords the caption already says;
 * - new text gets the keywords the composer showed under "Keywords for this
 *   post" (`seo_keywords` on the assistant's request, handed to the agent and
 *   its view as `seo_keywords_chosen`; only catalogue keywords count), else
 *   the app's pick for what it is about, going out today.
 */
final class TryPostPromptKeywords
{
    /**
     * @param  array<string, mixed>  $data  The prompt view's data
     * @return list<array{text: string, main: bool}>
     */
    public static function of(Workspace $workspace, array $data, string $focus): array
    {
        if (data_get($data, 'writes_new_text') === false) {
            return BrandKeywords::saidIn($workspace, (string) data_get($data, 'current_content'));
        }

        $chosen = data_get($data, 'seo_keywords_chosen');

        return is_array($chosen)
            ? KeywordPicker::chosen($workspace, $chosen)
            : KeywordPicker::pick($workspace, PostContext::forTopic($focus));
    }
}
