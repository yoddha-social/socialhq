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
use App\Models\WorkspaceLabel;
use Illuminate\Support\Collection;

/**
 * An owner's workspace with two LinkedIn channels in Kathmandu, and the person
 * who opens the composer: the owner, or a member with the given access.
 *
 * @return array{0: User, 1: Workspace, 2: Collection<int, SocialAccount>}
 */
function whenBarWorkspace(string $access = 'owner'): array
{
    $owner = User::factory()->create(['timezone' => 'Asia/Kathmandu']);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($owner->account);

    $user = $access === 'owner' ? $owner->fresh() : workspaceMember($workspace, $access, ['timezone' => 'Asia/Kathmandu']);
    $channels = SocialAccount::factory()->count(2)->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'Asia/Kathmandu']);

    return [$user, $workspace, $channels];
}

/**
 * Opens a new post on both channels with a caption. Without posting times the
 * composer starts on a set time, the next full hour.
 *
 * @param  Collection<int, SocialAccount>  $channels
 */
function whenBarNewPost(Collection $channels): mixed
{
    $page = visit(route('app.posts.create'))->resize(1440, 900);
    waitFor($page, '@composer-add-account');
    $page->click('@composer-add-account');

    foreach ($channels as $channel) {
        waitFor($page, "@composer-account-option-{$channel->id}");
        $page->click("@composer-account-option-{$channel->id}");
    }

    waitFor($page, '[data-testid^="composer-caption-"]');
    $caption = (string) $page->script('document.querySelector(\'[data-testid^="composer-caption-"]\').dataset.testid');
    $page->fill("@{$caption}", 'Winter menu is here');
    waitForState($page, '@composer-submit', "element.dataset.scheduleMode === 'custom'");

    return $page;
}

function whenBarRepeat(mixed $page, int $interval, string $frequency, int $times): void
{
    $page->click('@composer-repeat-trigger');
    waitFor($page, '@composer-repeat-panel');
    $page->fill('@composer-repeat-interval', (string) $interval);
    $page->click('@composer-repeat-frequency');
    waitFor($page, "@composer-repeat-frequency-option-{$frequency}");
    $page->click("@composer-repeat-frequency-option-{$frequency}");
    $page->fill('@composer-repeat-times', (string) $times);
    waitFor($page, '@composer-repeat-summary');
    $page->click('@composer-repeat-apply');
    waitForGone($page, '@composer-repeat-panel');
}

test('the footer holds the labels, the repeat and the time with its zone, and the button counts the posts', function () {
    [$user, $workspace, $channels] = whenBarWorkspace();
    WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Menu']);
    $this->actingAs($user);

    $page = whenBarNewPost($channels);

    $bar = $page->script(<<<'JS'
        (() => {
            const footer = document.querySelector('[data-testid="composer-footer"]');
            const text = (id) => document.querySelector(`[data-testid="${id}"]`)?.textContent.trim() ?? null;
            const actions = document.querySelector('[data-testid="composer-footer-actions"]');
            const rects = ['composer-tags-trigger', 'composer-repeat-trigger', 'composer-schedule-trigger', 'composer-save-draft', 'composer-submit']
                .map((id) => footer.querySelector(`[data-testid="${id}"]`)?.getBoundingClientRect() ?? null);
            return {
                inFooter: rects.every((rect) => rect !== null),
                labelsInHeader: document.querySelector('[data-testid="composer-header"] [data-testid="composer-tags-trigger"]') !== null,
                order: [...actions.querySelectorAll('[data-testid="composer-schedule-trigger"], [data-testid="composer-save-draft"], [data-testid="composer-submit"]')].map((element) => element.dataset.testid),
                oneRow: rects.every((rect) => Math.abs(rect.top + rect.height / 2 - (rects[0].top + rects[0].height / 2)) < 4),
                weekday: text('composer-schedule-trigger-weekday'),
                zone: text('composer-schedule-trigger-zone'),
                repeat: text('composer-repeat-label'),
                submit: text('composer-submit'),
            };
        })()
    JS);

    expect($bar['inFooter'])->toBeTrue()
        ->and($bar['labelsInHeader'])->toBeFalse()
        ->and($bar['order'])->toBe(['composer-schedule-trigger', 'composer-save-draft', 'composer-submit'])
        ->and($bar['oneRow'])->toBeTrue()
        ->and($bar['weekday'])->toMatch('/^\p{L}{2,}\.?,$/u')
        ->and($bar['zone'])->toBe('Asia/Kathmandu')
        ->and($bar['repeat'])->toBe('Doesn’t repeat')
        ->and($bar['submit'])->toBe('Schedule 2 posts');

    $page->click('@composer-tags-trigger');
    waitFor($page, '[data-testid^="composer-label-option-"]');
    $page->click('[data-testid^="composer-label-option-"]');
    waitForText($page, '@composer-tags-trigger', 'Menu');

    $page->assertNoJavaScriptErrors();
});

test('a repeat picked in the footer is saved with every post', function () {
    [$user, $workspace, $channels] = whenBarWorkspace();
    $this->actingAs($user);

    $page = whenBarNewPost($channels);
    whenBarRepeat($page, 2, 'week', 3);

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="composer-repeat-label"]\').textContent')))->toBe('Every 2 weeks, 3 times');

    $page->click('@composer-submit');
    waitForGone($page, '@post-composer-dialog');
    waitForDatabase($page, fn (): bool => Post::query()->where('workspace_id', $workspace->id)->count() === 2);

    $posts = Post::query()->where('workspace_id', $workspace->id)->get();

    expect($posts)->toHaveCount(2);
    $posts->each(fn (Post $post) => expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->recurrence_interval)->toBe(2)
        ->and($post->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_remaining)->toBe(3));
    $page->assertNoJavaScriptErrors();
});

test('the repeat waits for a set time and says why', function () {
    [$user, , $channels] = whenBarWorkspace();
    $this->actingAs($user);

    $page = whenBarNewPost($channels);
    whenBarRepeat($page, 1, 'month', 2);

    $page->click('@composer-schedule-trigger');
    waitFor($page, '@composer-schedule-picker');
    $page->click('@composer-schedule-more-actions');
    waitFor($page, '@composer-schedule-now');
    $page->click('@composer-schedule-now');
    waitForState($page, '@composer-repeat-trigger', 'element.disabled');

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="composer-repeat-label"]\').textContent')))->toBe('Doesn’t repeat')
        ->and(trim((string) $page->script('document.getElementById("composer-repeat-needs-time").textContent')))->toBe(__('socialhq.composer.repeat.needs_time'))
        ->and(trim((string) $page->script('document.querySelector(\'[data-testid="composer-submit"]\').textContent')))->toBe('Publish 2 posts');

    $page->click('@composer-schedule-trigger');
    waitFor($page, '@composer-schedule-custom');
    $page->click('@composer-schedule-custom');
    waitFor($page, '@composer-schedule-done');
    $page->click('@composer-schedule-done');
    waitForState($page, '@composer-repeat-trigger', '!element.disabled');

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="composer-repeat-label"]\').textContent')))->toBe('Every month, 2 times');
    $page->assertNoJavaScriptErrors();
});

test('a member whose posts need approval gets no repeat and a button that asks for approval', function () {
    [$member, , $channels] = whenBarWorkspace('approval');
    $this->actingAs($member);

    $page = whenBarNewPost($channels);

    $page->assertMissing('@composer-repeat-trigger');
    expect(trim((string) $page->script('document.querySelector(\'[data-testid="composer-submit"]\').textContent')))->toBe('Request approval for 2 posts');
    $page->assertNoJavaScriptErrors();
});

test('editing a post of a series shows its repeat and keeps the series when saved', function () {
    [$user, $workspace, $channels] = whenBarWorkspace();
    $at = now()->addDays(3)->startOfHour();
    $post = Post::factory()->forAccount($channels->first(), ContentType::LinkedInPost)->create([
        'user_id' => $user->id,
        'content' => 'Weekly special',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => $at,
        'meta' => ['visibility' => 'PUBLIC'],
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 4,
        'recurrence_origin_at' => $at->copy()->subWeek(),
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1440, 900);
    waitFor($page, '@composer-repeat-trigger');
    waitForText($page, '@composer-repeat-label', 'Every week, 4 times');

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="composer-submit"]\').textContent')))->toBe('Schedule');

    $page->fill("@composer-caption-{$post->social_account_id}", 'Weekly special, edited');
    $page->click('@composer-submit');
    waitForGone($page, '@post-composer-dialog');
    waitForDatabase($page, fn (): bool => $post->fresh()->content === 'Weekly special, edited');

    $post->refresh();

    expect($post->content)->toBe('Weekly special, edited')
        ->and($post->recurrence_remaining)->toBe(4)
        ->and($post->recurrence_origin_at?->toIso8601String())->toBe($at->copy()->subWeek()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});
