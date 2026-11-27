<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\SocialHq\Plan\MustHave;
use App\SocialHq\Plan\PlannerOptions;
use Carbon\CarbonImmutable;

/*
 * What a run's instructions read as once saved (generation_options) or
 * remembered by the brand (planner_options): the job and the dialog read
 * them back through PlannerOptions, so a broken value never reaches the AI.
 */

test('days off read as weekdays 0 to 6, each once, in order', function () {
    expect(PlannerOptions::weekdays([6, '0', 6, 7, -1, 'Sat', null, 3]))->toBe([0, 3, 6])
        ->and(PlannerOptions::weekdays('6'))->toBe([]);
});

test('must-haves keep a day of the month and a focus, at most ten, named M1 on in their order', function () {
    $month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $day = fn (int $offset): string => $month->addDays($offset)->toDateString();

    $mustHaves = MustHave::listFrom([
        ['date' => $day(3), 'channel' => '', 'content_type' => 'not-a-format', 'focus' => '  Month-end   checklist '],
        ['date' => $month->subDay()->toDateString(), 'focus' => 'Before the month'],
        ['date' => $day(4), 'focus' => ''],
        ['date' => $day(5), 'channel' => 'channel-key', 'content_type' => ContentType::InstagramReel->value, 'focus' => str_repeat('a', MustHave::MAX_FOCUS_LENGTH + 20)],
        ...array_fill(0, MustHave::MAX, ['date' => $day(6), 'focus' => 'Filler']),
    ], $month);

    expect($mustHaves)->toHaveCount(MustHave::MAX)
        ->and($mustHaves[0]->id)->toBe('M1')
        ->and($mustHaves[0]->toArray())->toBe(['date' => $day(3), 'channel' => null, 'content_type' => null, 'focus' => 'Month-end checklist'])
        ->and($mustHaves[1]->id)->toBe('M2')
        ->and([$mustHaves[1]->channelId, $mustHaves[1]->contentType, mb_strlen($mustHaves[1]->focus)])->toBe(['channel-key', ContentType::InstagramReel, MustHave::MAX_FOCUS_LENGTH])
        // The same must-have is the same one in every run; another focus is another must-have.
        ->and($mustHaves[2]->key())->toBe($mustHaves[3]->key())
        ->and($mustHaves[0]->key())->not->toBe($mustHaves[2]->key());
});

test('the brand remembers the strategy and days off only while the instructions are kept', function () {
    $month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $options = PlannerOptions::within($month, ['days_off' => [6], 'old_calendars' => false]);

    expect($options->remembered(' Push the brand. ', true))->toBe(['old_calendars' => false, 'past_posts' => true, 'audience_events' => false, 'strategy' => 'Push the brand.', 'days_off' => [6]])
        ->and($options->remembered('Push the brand.', false))->toBe(['old_calendars' => false, 'past_posts' => true, 'audience_events' => false]);
});
