<?php

declare(strict_types=1);

namespace App\SocialHq\Plan;

use App\Enums\PostPlatform\ContentType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A post the team requires in a run of "Generate with AI": a day, a channel
 * (or any), a format (or any) and what it must be about. The app guarantees
 * it, not the AI: SuggestionChecks puts the AI's item that names it on its
 * day, channel and format, and adds it itself when the AI missed it. Saved on
 * the plan with the run's options (generation_options), since it is dated.
 */
final readonly class MustHave
{
    /** Must-haves one run may ask for. */
    public const MAX = 10;

    public const MAX_FOCUS_LENGTH = 300;

    /**
     * @param  string  $id  what the AI is told to name it by in this run ("M1"), by its place in the run's list
     */
    public function __construct(
        public string $id,
        public CarbonImmutable $date,
        public ?string $channelId,
        public ?ContentType $contentType,
        public string $focus,
    ) {}

    /**
     * Must-haves from submitted or saved values, in their order: only those
     * with a day inside the month and a focus, at most MAX. Anything else in
     * a value (an unknown format, a blank channel) reads as "any".
     *
     * @return list<self>
     */
    public static function listFrom(mixed $values, CarbonImmutable $month): array
    {
        $end = PlanMonth::end($month);
        $list = [];

        foreach (is_array($values) ? array_values($values) : [] as $value) {
            $dateText = (string) data_get($value, 'date');
            $focus = Str::limit(Str::squish((string) data_get($value, 'focus')), self::MAX_FOCUS_LENGTH, '');
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateText) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $dateText, 'UTC') : null;

            if ($date === null || $date->toDateString() !== $dateText || ! $date->betweenIncluded($month, $end) || $focus === '' || count($list) >= self::MAX) {
                continue;
            }

            $channel = trim((string) data_get($value, 'channel'));
            $list[] = new self('M'.(count($list) + 1), $date, $channel === '' ? null : $channel, ContentType::tryFrom((string) data_get($value, 'content_type')), $focus);
        }

        return $list;
    }

    /**
     * Who the must-have is, whatever run asks for it again: an item that
     * still carries this key covers it, so a must-have someone accepted is
     * never added a second time.
     */
    public function key(): string
    {
        return sha1(implode('|', [$this->date->toDateString(), $this->channelId ?? '', $this->contentType?->value ?? '', $this->focus]));
    }

    /**
     * @return array{date: string, channel: string|null, content_type: string|null, focus: string}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'channel' => $this->channelId,
            'content_type' => $this->contentType?->value,
            'focus' => $this->focus,
        ];
    }
}
