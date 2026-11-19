<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * @return array{0: User, 1: SocialAccount, 2: CarbonImmutable}
 */
function calendarPostListSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [
        $user,
        SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']),
        CarbonImmutable::now('UTC')->addDays(10)->startOfDay(),
    ];
}

function calendarPostListPost(SocialAccount $account, ?CarbonImmutable $at, PostStatus $status, string $content = 'A post'): Post
{
    return Post::factory()->forAccount($account)->create([
        'user_id' => $account->workspace->user_id,
        'status' => $status,
        'scheduled_at' => $at,
        'content' => $content,
    ]);
}

test('the post list shows scheduled posts, drafts and sent posts in tabs with their counts', function () {
    [$user, $linkedin, $day] = calendarPostListSetup();
    $scheduled = calendarPostListPost($linkedin, $day->setTime(9, 0), PostStatus::Scheduled);
    $pending = calendarPostListPost($linkedin, $day->setTime(11, 0), PostStatus::PendingApproval);
    $datedDraft = calendarPostListPost($linkedin, $day->setTime(13, 0), PostStatus::Draft);
    $undated = calendarPostListPost($linkedin, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'days', 'day' => $day->format('Y-m-d')]))->resize(1440, 900);
    waitFor($page, '@calendar-no-date');
    $page->click('@calendar-no-date');
    waitFor($page, "@calendar-undated-{$undated->id}");

    // It opens on Drafts: the dated draft of these days, then the drafts with no date.
    $page->assertAttribute('@calendar-list-tab-drafts', 'aria-pressed', 'true')
        ->assertSeeIn('@calendar-list-count-scheduled', '2')
        ->assertSeeIn('@calendar-list-count-drafts', '2')
        ->assertSeeIn('@calendar-list-count-sent', '0')
        ->assertPresent("@calendar-list-post-{$datedDraft->id}")
        ->assertMissing("@calendar-list-post-{$scheduled->id}")
        ->click('@calendar-list-tab-scheduled');
    waitFor($page, "@calendar-list-post-{$scheduled->id}");

    $page->assertPresent("@calendar-list-post-{$pending->id}")
        ->assertSeeIn("@calendar-list-status-{$pending->id}", __('calendar.post_status.pending_approval'))
        ->assertMissing("@calendar-list-post-{$datedDraft->id}")
        ->assertMissing("@calendar-undated-{$undated->id}")
        ->click('@calendar-list-tab-sent');
    waitFor($page, '@calendar-list-empty');

    $page->assertSeeIn('@calendar-list-empty', __('socialhq.calendar.empty_sent'))->assertNoJavaScriptErrors();
});

test('a card of the post list finds its post on the calendar', function () {
    [$user, $linkedin, $day] = calendarPostListSetup();
    $scheduled = calendarPostListPost($linkedin, $day->setTime(21, 0), PostStatus::Scheduled);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'days', 'day' => $day->format('Y-m-d'), 'undated' => 1]))->resize(1440, 900);
    waitFor($page, '@calendar-list-tab-scheduled');
    $page->click('@calendar-list-tab-scheduled');
    waitFor($page, "@calendar-list-post-{$scheduled->id}");
    $page->click("@calendar-list-post-{$scheduled->id}");
    waitForState($page, "@calendar-post-{$scheduled->id}", 'element.hasAttribute("data-located")');
    waitForState($page, "@calendar-post-{$scheduled->id}", <<<'JS'
        (() => {
            const grid = document.querySelector('[data-testid="calendar-time-grid"]').getBoundingClientRect();
            const box = element.getBoundingClientRect();

            return box.top >= grid.top && box.bottom <= grid.bottom;
        })()
    JS);

    $page->assertNoJavaScriptErrors();
});

test('a week chip shows the first line of its post and spells out a post waiting for approval', function () {
    [$user, $linkedin, $day] = calendarPostListSetup();
    $scheduled = calendarPostListPost($linkedin, $day->setTime(9, 0), PostStatus::Scheduled, 'Launch day is here');
    $pending = calendarPostListPost($linkedin, $day->setTime(11, 0), PostStatus::PendingApproval, 'Needs a look');
    $stackedOne = calendarPostListPost($linkedin, $day->setTime(15, 0), PostStatus::Scheduled, 'Stacked one');
    $stackedTwo = calendarPostListPost($linkedin, $day->setTime(15, 30), PostStatus::Scheduled, 'Stacked two');
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'days', 'day' => $day->format('Y-m-d')]))->resize(1440, 900);
    waitFor($page, "@calendar-post-{$scheduled->id}");

    $page->assertSeeIn("@calendar-post-text-{$scheduled->id}", 'Launch day is here')
        ->assertSeeIn("@calendar-post-pending-{$pending->id}", __('socialhq.calendar.chip_approval'))
        ->assertSeeIn("@calendar-post-text-{$pending->id}", 'Needs a look')
        ->assertMissing("@calendar-post-text-{$stackedOne->id}")
        ->assertMissing("@calendar-post-text-{$stackedTwo->id}")
        ->assertNoJavaScriptErrors();
});
