<?php

declare(strict_types=1);

namespace App\SocialHq\Plan\Ai;

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\User;
use App\SocialHq\Knowledge\BrandKnowledge;
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

    public static function execute(PlanItem $item, User $user): ?ItemVersion
    {
        $item->loadMissing(['event', 'socialAccount', 'latestVersion']);

        $latest = $item->latestVersion;
        $isVideo = $item->creative === CreativeKind::Video;
        $maxLength = ItemPage::maxLength($item);
        $maxHashtags = Platform::tryFrom((string) $item->platform)?->maxHashtags() ?? self::DEFAULT_MAX_HASHTAGS;

        $response = (new CopyWriter(
            language: $user->locale->promptLanguage(),
            context: self::context($item),
            brandKnowledge: BrandKnowledge::forPrompt($item->workspace, $item->title),
            isVideo: $isVideo,
            isRewrite: $latest !== null,
            maxLength: $maxLength === null ? null : self::captionBudget($maxLength, $maxHashtags, $latest?->hashtags),
            maxHashtags: $maxHashtags,
        ))->prompt($latest === null ? 'Write the copy for this post.' : 'Rewrite the copy for this post.');

        $caption = trim((string) data_get($response, 'caption'));

        if ($caption === '') {
            return null;
        }

        [$caption, $hashtags, $trimmed] = self::fit(
            Str::substr($caption, 0, Post::CONTENT_MAX_LENGTH),
            self::hashtags((string) data_get($response, 'hashtags'), $maxHashtags),
            $maxLength,
        );

        $notes = trim((string) data_get($response, 'notes'));

        if ($trimmed) {
            $notes = trim("{$notes}\n\n".__('socialhq_production.item.copy.ai_trimmed', [], $user->locale->value));
        }

        return ItemCopy::saveVersion($item, $user, [
            'caption' => $caption,
            'hashtags' => $hashtags,
            'script' => $isVideo ? Str::substr(trim((string) data_get($response, 'script')), 0, 20000) : null,
            'notes' => Str::substr($notes, 0, 5000),
        ], ItemSource::Ai);
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
     */
    public static function hashtags(string $raw, int $max): ?string
    {
        $tags = collect(preg_split('/[\s,]+/u', $raw) ?: [])
            ->map(fn (string $tag): string => ltrim(trim($tag), '#'))
            ->filter(fn (string $tag): bool => $tag !== '' && preg_match('/^[\p{L}\p{N}_]+$/u', $tag) === 1)
            ->unique(fn (string $tag): string => Str::lower($tag))
            ->take($max)
            ->map(fn (string $tag): string => "#{$tag}");

        $joined = Str::substr($tags->implode(' '), 0, 500);

        return $joined === '' ? null : $joined;
    }
}
