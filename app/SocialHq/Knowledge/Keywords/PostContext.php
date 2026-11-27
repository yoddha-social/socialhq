<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Keywords;

use App\SocialHq\Plan\Ai\WriteItemCopy;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;

/**
 * What KeywordPicker knows about the post it picks keywords for: its
 * pillar, the event it is for (as an occasion key), the day it goes out,
 * what it is about (for products and the topic) and a number that makes the
 * picks take turns from one post to the next.
 */
final readonly class PostContext
{
    public function __construct(
        public ?string $pillar = null,
        public ?string $occasion = null,
        public ?CarbonImmutable $day = null,
        public string $focus = '',
        public int $seed = 0,
    ) {}

    /**
     * A plan item: its pillar, event, day and what it is about; items take
     * turns by id.
     */
    public static function forItem(PlanItem $item): self
    {
        $item->loadMissing('event');

        return new self(
            pillar: filled($item->pillar) ? (string) $item->pillar : null,
            occasion: $item->event === null ? null : Occasion::key($item->event->key),
            day: CarbonImmutable::parse($item->scheduled_on->toDateString()),
            focus: WriteItemCopy::focus($item),
            seed: (int) $item->id,
        );
    }

    /**
     * A post known only by what it is about and its day (today when none):
     * posts of different days take turns.
     */
    public static function forTopic(?string $focus = null, ?CarbonImmutable $day = null): self
    {
        $day = CarbonImmutable::parse(($day ?? CarbonImmutable::today())->toDateString());

        return new self(day: $day, focus: (string) $focus, seed: intdiv($day->getTimestamp(), 86400));
    }
}
