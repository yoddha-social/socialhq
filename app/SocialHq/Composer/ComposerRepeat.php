<?php

declare(strict_types=1);

namespace App\SocialHq\Composer;

use App\Actions\Post\UpdatePostRecurrence;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Support\PostingSchedule;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The repeat picked in the composer's footer ("Every week, 4 times"): the rule
 * the post card's "Make Recurring" sets, saved with the post instead of after it.
 * Only a post scheduled at a set time, by someone who publishes directly, repeats;
 * the web store and update requests check both before anything is saved.
 */
final class ComposerRepeat
{
    /**
     * Whether the last occurrence would fall after the latest instant a post may
     * be scheduled at, counted in the channel's time zone as the series runs.
     *
     * @param  array{interval: int|string, frequency: string, times: int|string}  $rule
     */
    public static function endsTooLate(CarbonInterface $scheduledAt, ?string $timezone, array $rule): bool
    {
        $frequency = RecurrenceFrequency::from((string) data_get($rule, 'frequency'));
        $steps = (int) data_get($rule, 'interval') * (int) data_get($rule, 'times');

        return $frequency
            ->advance($scheduledAt->toImmutable()->setTimezone(Timezone::normalize($timezone)), $steps)
            ->greaterThan(CarbonImmutable::parse(PostingSchedule::MAX_INSTANT, 'UTC'));
    }

    /**
     * Gives each saved post the rule, or takes it away when the rule is null. A
     * post that did not end up scheduled at a time is left alone, and so is one
     * that already follows the same rule, so saving one post of a series keeps
     * its place in it.
     *
     * @param  iterable<Post>  $posts
     * @param  array{interval: int|string, frequency: string, times: int|string}|null  $rule
     */
    public static function apply(iterable $posts, ?array $rule): void
    {
        foreach ($posts as $post) {
            $post->refresh();

            if ($post->status !== PostStatus::Scheduled || $post->scheduled_at === null || self::follows($post, $rule)) {
                continue;
            }

            UpdatePostRecurrence::execute($post, $rule);
        }
    }

    /**
     * @param  array{interval: int|string, frequency: string, times: int|string}|null  $rule
     */
    private static function follows(Post $post, ?array $rule): bool
    {
        if ($rule === null) {
            return ! $post->isRecurring();
        }

        return $post->isRecurring()
            && $post->recurrence_interval === (int) data_get($rule, 'interval')
            && $post->recurrence_frequency?->value === data_get($rule, 'frequency')
            && $post->recurrence_remaining === (int) data_get($rule, 'times');
    }
}
