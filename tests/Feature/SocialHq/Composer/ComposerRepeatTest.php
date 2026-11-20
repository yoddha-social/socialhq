<?php

declare(strict_types=1);

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);
});

/**
 * What the composer sends for a post on these channels.
 *
 * @param  list<SocialAccount>  $channels
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function composerRepeatStore(array $channels, array $overrides = []): array
{
    return [
        'status' => PostStatus::Scheduled->value,
        'content' => 'Winter menu is here',
        'media' => [],
        'scheduled_at' => now()->addDays(3)->startOfMinute()->toIso8601String(),
        'destinations' => collect($channels)->map(fn (SocialAccount $channel): array => [
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ])->all(),
        ...$overrides,
    ];
}

/**
 * A scheduled post on the channel, in a weekly series that started a week
 * before its own time.
 */
function composerRepeatSeriesPost(SocialAccount $channel, User $user): Post
{
    $at = now()->addDays(3)->startOfMinute();

    return Post::factory()->forAccount($channel)->create([
        'user_id' => $user->id,
        'content' => 'Weekly special',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => $at,
        'meta' => ['visibility' => 'PUBLIC'],
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 3,
        'recurrence_origin_at' => $at->copy()->subWeek(),
    ])->refresh();
}

/**
 * What the composer sends when it saves the post at its own time.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function composerRepeatUpdate(Post $post, array $overrides = []): array
{
    return [
        'status' => PostStatus::Scheduled->value,
        'content' => 'Weekly special, edited',
        'content_type' => ContentType::LinkedInPost->value,
        'meta' => ['visibility' => 'PUBLIC'],
        'scheduled_at' => $post->scheduled_at->toIso8601String(),
        ...$overrides,
    ];
}

test('scheduling posts with a repeat gives every post the rule', function () {
    $other = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'Asia/Kathmandu']);

    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->post(route('app.posts.store'), composerRepeatStore([$this->channel, $other], [
            'recurrence' => ['interval' => 2, 'frequency' => 'week', 'times' => 3],
        ]))
        ->assertSessionHasNoErrors();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->get();

    expect($posts)->toHaveCount(2);
    $posts->each(fn (Post $post) => expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->recurrence_interval)->toBe(2)
        ->and($post->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_remaining)->toBe(3)
        ->and($post->recurrence_origin_at)->toBeNull());
});

test('scheduling without a repeat leaves the posts single', function () {
    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->post(route('app.posts.store'), composerRepeatStore([$this->channel], ['recurrence' => null]))
        ->assertSessionHasNoErrors();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->sole()->isRecurring())->toBeFalse();
});

test('a repeat needs a set date and time', function (array $overrides) {
    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->post(route('app.posts.store'), composerRepeatStore([$this->channel], [
            'recurrence' => ['interval' => 1, 'frequency' => 'week', 'times' => 2],
            ...$overrides,
        ]))
        ->assertSessionHasErrors(['recurrence' => __('posts.recurrence.errors.not_scheduled')]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->exists())->toBeFalse();
})->with([
    'a draft' => [['status' => PostStatus::Draft->value]],
    'published now' => [['status' => PostStatus::Publishing->value, 'scheduled_at' => null]],
    'the queue' => [['queue' => 'next', 'scheduled_at' => null]],
]);

test('a member whose posts need approval cannot repeat them', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)
        ->from(route('app.posts.index'))
        ->post(route('app.posts.store'), composerRepeatStore([$this->channel], [
            'recurrence' => ['interval' => 1, 'frequency' => 'week', 'times' => 2],
        ]))
        ->assertSessionHasErrors(['recurrence' => __('socialhq.composer.repeat.errors.approval')]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->exists())->toBeFalse();
});

test('a repeat whose last post would fall after 2037 is refused', function () {
    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->post(route('app.posts.store'), composerRepeatStore([$this->channel], [
            'recurrence' => ['interval' => 100, 'frequency' => 'year', 'times' => 1],
        ]))
        ->assertSessionHasErrors(['recurrence' => __('posts.recurrence.errors.too_far')]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->exists())->toBeFalse();
});

test('a repeat out of range is refused', function (array $rule, string $field) {
    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->post(route('app.posts.store'), composerRepeatStore([$this->channel], ['recurrence' => $rule]))
        ->assertSessionHasErrors("recurrence.{$field}");
})->with([
    'no interval' => [['interval' => 0, 'frequency' => 'week', 'times' => 2], 'interval'],
    'an unknown unit' => [['interval' => 1, 'frequency' => 'hour', 'times' => 2], 'frequency'],
    'too many times' => [['interval' => 1, 'frequency' => 'week', 'times' => 101], 'times'],
]);

test('saving a post of a series with the same repeat keeps its place in the series', function () {
    $post = composerRepeatSeriesPost($this->channel, $this->owner);
    $origin = $post->recurrence_origin_at->toIso8601String();

    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->put(route('app.posts.update', $post), composerRepeatUpdate($post, [
            'recurrence' => ['interval' => 1, 'frequency' => 'week', 'times' => 3],
        ]))
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->content)->toBe('Weekly special, edited')
        ->and($post->recurrence_remaining)->toBe(3)
        ->and($post->recurrence_origin_at?->toIso8601String())->toBe($origin);
});

test('changing the repeat while editing starts a new series from the post', function () {
    $post = composerRepeatSeriesPost($this->channel, $this->owner);

    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->put(route('app.posts.update', $post), composerRepeatUpdate($post, [
            'recurrence' => ['interval' => 1, 'frequency' => 'month', 'times' => 6],
        ]))
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->recurrence_frequency)->toBe(RecurrenceFrequency::Month)
        ->and($post->recurrence_remaining)->toBe(6)
        ->and($post->recurrence_origin_at)->toBeNull();
});

test('choosing "Don’t repeat" while editing stops the series', function () {
    $post = composerRepeatSeriesPost($this->channel, $this->owner);

    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->put(route('app.posts.update', $post), composerRepeatUpdate($post, ['recurrence' => null]))
        ->assertSessionHasNoErrors();

    expect($post->refresh()->isRecurring())->toBeFalse();
});

test('a save that sends no repeat leaves the series alone', function () {
    $post = composerRepeatSeriesPost($this->channel, $this->owner);

    $this->actingAs($this->owner)
        ->from(route('app.posts.index'))
        ->put(route('app.posts.update', $post), composerRepeatUpdate($post))
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->isRecurring())->toBeTrue()
        ->and($post->recurrence_origin_at)->not->toBeNull();
});
