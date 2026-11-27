<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\User;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordPicker;
use App\SocialHq\Knowledge\Look\BrandLook;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemCopy;
use App\SocialHq\Plan\ItemPage;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Support\Str;

/**
 * "Write with AI" / "Rewrite with AI" on an item: asks CopyWriter for copy
 * and saves what comes back as the item's next version (source ai). Nothing
 * is overwritten. Returns null when the answer has no caption.
 */
final class WriteItemCopy
{
    public const int DEFAULT_MAX_HASHTAGS = 8;

    /**
     * @param  string|null  $instruction  what the team asked for in its own words ("shorter", "add the price"), or null
     */
    public static function execute(PlanItem $item, User $user, ?string $instruction = null): ?ItemVersion
    {
        $item->loadMissing(['event', 'socialAccount', 'latestVersion']);

        // One line, and nothing that could close the prompt's quoted block.
        $instruction = Str::squish(str_replace('"""', '"', (string) $instruction));

        $latest = $item->latestVersion;
        $isVideo = $item->creative === CreativeKind::Video;
        $maxLength = ItemPage::maxLength($item);
        $maxHashtags = Platform::tryFrom((string) $item->platform)?->maxHashtags() ?? self::DEFAULT_MAX_HASHTAGS;

        $context = self::context($item);
        $brandKnowledge = '';
        $focus = trim(self::focus($item)."\n{$instruction}");
        // Only the keywords picked for this post (or chosen by a person) go to the writer.
        $keywords = KeywordPicker::forItem($item);

        // The item's workspace decides whose AI it uses, whatever workspace the user has open.
        // The brand knowledge is read there too: its search may use the company's AI one day.
        $response = AiTenant::run($item->workspace, function () use ($item, $user, $context, $instruction, $isVideo, $latest, $maxLength, $maxHashtags, $focus, $keywords, &$brandKnowledge) {
            $brandKnowledge = BrandKnowledge::forPrompt($item->workspace, $focus);

            return (new CopyWriter(
                language: $user->locale->promptLanguage(),
                context: $context,
                brandKnowledge: $brandKnowledge,
                isVideo: $isVideo,
                isRewrite: $latest !== null,
                maxLength: $maxLength === null ? null : self::captionBudget($maxLength, $maxHashtags, $latest?->hashtags),
                maxHashtags: $maxHashtags,
                visualStyle: BrandLook::of($item->workspace)?->copyLine() ?? '',
                instruction: $instruction,
                seoKeywords: $keywords,
            ))->prompt($latest === null ? 'Write the copy for this post.' : 'Rewrite the copy for this post.');
        });

        $caption = trim((string) data_get($response, 'caption'));

        if ($caption === '') {
            return null;
        }

        // Models often paste the hashtags into the caption as well, though the prompt says not to:
        // the app keeps them in the hashtag list only, or the post would carry them twice.
        $listed = self::tagsIn((string) data_get($response, 'hashtags'));
        [$caption, $trailing] = self::withoutTrailingHashtags($caption, $listed);

        [$caption, $hashtags, $trimmed] = self::fit(
            Str::substr($caption, 0, Post::CONTENT_MAX_LENGTH),
            self::hashtags([...$listed, ...$trailing], $maxHashtags),
            $maxLength,
        );

        $notes = trim((string) data_get($response, 'notes'));

        if ($trimmed) {
            $notes = trim("{$notes}\n\n".__('socialhq_production.item.copy.ai_trimmed', [], $user->locale->value));
        }

        $script = $isVideo ? Str::substr(trim((string) data_get($response, 'script')), 0, 20000) : null;

        return ItemCopy::saveVersion($item, $user, [
            'caption' => $caption,
            'hashtags' => $hashtags,
            'script' => $script,
            'notes' => Str::substr($notes, 0, 5000),
            'unsupported_details' => UnsupportedDetails::find(
                trim("{$caption}\n\n{$script}"),
                self::sources($item, $context, $brandKnowledge, $instruction, BrandKeywords::sourceText($keywords)),
            ) ?: null,
        ], ItemSource::Ai);
    }

    /**
     * What the writer was given to write from, as one text: the item, its
     * event, the open change requests, the brand knowledge, the team's
     * request and the SEO keywords (a place in a keyword is not invented).
     * The latest version counts only when a person wrote it: what an earlier
     * AI version invented isn't a source.
     *
     * @param  array<string, mixed>  $context
     */
    public static function sources(PlanItem $item, array $context, string $brandKnowledge, string $instruction, string $keywords = ''): string
    {
        if ($item->latestVersion?->source === ItemSource::Ai) {
            unset($context['latest_version']);
        }

        return UnsupportedDetails::sourceText($context, $brandKnowledge, $instruction, $keywords);
    }

    /**
     * What the copy is about, for picking brand knowledge: the item's title,
     * objective, angle, pillar, event and format, and the open change requests.
     */
    public static function focus(PlanItem $item): string
    {
        return collect([
            $item->title,
            $item->objective,
            $item->angle,
            $item->pillar,
            $item->event?->name,
            $item->content_type?->label(),
            ...ItemCopy::openChangeRequests($item),
        ])->map(fn (mixed $part): string => trim((string) $part))->filter()->implode("\n");
    }

    /**
     * How long the caption may be so it fits the channel with its hashtags
     * (and the blank line between): the latest hashtags' length when there
     * are some, else room for the most hashtags allowed.
     */
    public static function captionBudget(int $maxLength, int $maxHashtags, ?string $currentHashtags): int
    {
        $reserve = filled($currentHashtags) ? mb_strlen($currentHashtags) : $maxHashtags * 16;

        return max((int) floor($maxLength / 2), $maxLength - min($reserve, (int) floor($maxLength / 3)) - 2);
    }

    /**
     * Make caption + blank line + hashtags fit the channel: hashtags go first
     * (from the end), then the caption is cut with an ellipsis.
     *
     * @return array{0: string, 1: ?string, 2: bool}
     */
    public static function fit(string $caption, ?string $hashtags, ?int $maxLength): array
    {
        $length = fn (string $caption, ?string $hashtags): int => mb_strlen($caption) + (filled($hashtags) ? mb_strlen((string) $hashtags) + 2 : 0);

        if ($maxLength === null || $length($caption, $hashtags) <= $maxLength) {
            return [$caption, $hashtags, false];
        }

        $tags = filled($hashtags) ? explode(' ', (string) $hashtags) : [];

        while ($tags !== [] && $length($caption, implode(' ', $tags)) > $maxLength) {
            array_pop($tags);
        }

        $hashtags = $tags === [] ? null : implode(' ', $tags);

        if ($length($caption, $hashtags) > $maxLength) {
            $caption = rtrim(mb_substr($caption, 0, max(0, $maxLength - 1))).'…';
        }

        return [$caption, $hashtags, true];
    }

    /**
     * What the writer knows about the item, as plain data.
     *
     * @return array<string, mixed>
     */
    public static function context(PlanItem $item): array
    {
        $latest = $item->latestVersion;

        return array_filter([
            'title' => $item->title,
            'date' => $item->scheduled_on->toDateString(),
            'weekday' => $item->scheduled_on->englishDayOfWeek,
            'channel' => Platform::tryFrom((string) $item->platform)?->label(),
            'format' => $item->content_type?->label(),
            'creative' => $item->creative->value,
            'pillar' => $item->pillar,
            'objective' => $item->objective,
            'angle' => $item->angle,
            'event' => $item->event === null ? null : array_filter([
                'name' => $item->event->name,
                'starts_on' => $item->event->starts_on->toDateString(),
                'ends_on' => $item->event->ends_on->toDateString(),
                'description' => $item->event->description,
                'date_verified' => $item->event->isVerified(),
            ], fn (mixed $value): bool => $value !== null),
            'latest_version' => $latest === null ? null : array_filter([
                'number' => $latest->number,
                'caption' => $latest->caption,
                'hashtags' => $latest->hashtags,
                'script' => $latest->script,
                'notes' => $latest->notes,
            ], fn (mixed $value): bool => $value !== null),
            'open_change_requests' => ItemCopy::openChangeRequests($item) ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Hashtags as "#one #two", without duplicates and at most $max of them.
     *
     * @param  string|list<string>  $tags  the model's hashtags as written, or tags already read by tagsIn()
     */
    public static function hashtags(string|array $tags, int $max): ?string
    {
        $joined = collect(is_string($tags) ? self::tagsIn($tags) : $tags)
            ->map(fn (string $tag): string => ltrim(trim($tag), '#'))
            ->filter(fn (string $tag): bool => $tag !== '' && preg_match('/^[\p{L}\p{M}\p{N}_]+$/u', $tag) === 1)
            ->unique(fn (string $tag): string => Str::lower($tag))
            ->take($max)
            ->map(fn (string $tag): string => "#{$tag}")
            ->implode(' ');

        $joined = Str::substr($joined, 0, 500);

        return $joined === '' ? null : $joined;
    }

    /**
     * The tags in the model's hashtags, without "#". A "#" starts each tag,
     * so a tag written with spaces ("#ghar ko swad") becomes one word
     * ("GharKoSwad") instead of three tags; without any "#", commas or
     * spaces separate them.
     *
     * @return list<string>
     */
    public static function tagsIn(string $raw): array
    {
        $parts = str_contains($raw, '#')
            ? preg_split('/[#,\n]+/u', $raw)
            : preg_split(str_contains($raw, ',') ? '/[,\n]+/u' : '/\s+/u', $raw);

        return collect($parts ?: [])
            ->map(fn (string $part): string => self::joinWords($part))
            ->filter(fn (string $tag): bool => $tag !== '')
            ->values()
            ->all();
    }

    /**
     * The caption without the hashtags the model also wrote at its end: a
     * closing paragraph of hashtags only (its tags join the list), and then
     * hashtags ending the last line that the list already has. Hashtags
     * inside a sentence stay, and a caption of hashtags only is kept as it is.
     *
     * @param  list<string>  $listed  the tags of the hashtag list (tagsIn())
     * @return array{0: string, 1: list<string>} the caption, and the tags taken from it
     */
    public static function withoutTrailingHashtags(string $caption, array $listed): array
    {
        $lines = preg_split('/\R/u', rtrim($caption)) ?: [];
        $known = collect($listed)->map(fn (string $tag): string => Str::lower($tag));
        $block = [];

        // A closing paragraph of hashtag lines ("#one #two", "#ghar ko swad #three").
        while ($lines !== [] && preg_match('/^#[\p{L}\p{M}\p{N}_][\p{L}\p{M}\p{N}_#\h,.\-]*$/u', trim((string) end($lines))) === 1) {
            array_unshift($block, trim((string) array_pop($lines)));
        }

        $text = implode(' ', $block);
        $taken = self::tagsIn($text);
        // Tags, not a sentence that starts with one: no "tag" longer than three words.
        $shortTags = collect(preg_split('/#/u', $text) ?: [])
            ->every(fn (string $part): bool => count(preg_split('/[\s\-]+/u', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: []) <= 3);
        $isTags = $shortTags && (substr_count($text, '#') >= 2
            || ($taken !== [] && collect($taken)->every(fn (string $tag): bool => $known->contains(Str::lower($tag)))));

        if ($block === [] || ! $isTags || trim(implode("\n", $lines)) === '') {
            $lines = [...$lines, ...$block];
            $taken = [];
        }

        $known = $known->merge(collect($taken)->map(fn (string $tag): string => Str::lower($tag)));

        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }

        // Hashtags closing the last line, when the list has every one of them.
        $last = (string) array_pop($lines);

        if (preg_match('/^(.*?\S)((?:\h+#[\p{L}\p{M}\p{N}_]+)+)\h*$/u', $last, $match) === 1) {
            $tail = self::tagsIn($match[2]);

            if (collect($tail)->every(fn (string $tag): bool => $known->contains(Str::lower($tag)))) {
                $last = $match[1];
            }
        }

        return [rtrim(implode("\n", [...$lines, $last])), $taken];
    }

    /**
     * One tag from words: "ghar ko swad" or "small-batch" becomes
     * "GharKoSwad" or "SmallBatch"; a single word stays as written.
     * Punctuation around a word goes ("#Holi." is "Holi").
     */
    private static function joinWords(string $part): string
    {
        $words = collect(preg_split('/[\s\-]+/u', $part) ?: [])
            ->map(fn (string $word): string => (string) preg_replace('/^[^\p{L}\p{M}\p{N}_]+|[^\p{L}\p{M}\p{N}_]+$/u', '', $word))
            ->filter(fn (string $word): bool => $word !== '')
            ->values();

        return $words->count() > 1
            ? $words->map(fn (string $word): string => Str::ucfirst($word))->implode('')
            : (string) $words->first();
    }
}
