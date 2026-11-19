<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Channels\ConnectInvite;
use App\Support\PostingSchedule;

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function channelPageSetup(?PostingSchedule $schedule = null): array
{
    $user = User::factory()->create([
        'timezone' => 'UTC',
        'time_format' => TimeFormat::TwelveHour,
        'week_starts_on' => WeekStart::Monday,
    ]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'display_name' => 'Acme Corp',
        'timezone' => 'UTC',
        'posting_goal' => 3,
        'posting_schedule' => $schedule,
    ]);

    return [$user->fresh(), $workspace, $channel];
}

function channelPageWeekdaySchedule(): PostingSchedule
{
    return collect([1, 2, 3, 4, 5])->reduce(
        fn (PostingSchedule $schedule, int $day): PostingSchedule => $schedule->withTime($day, '09:00'),
        PostingSchedule::empty(),
    );
}

test('the channel page lists the channels and shows where the selected one stands', function () {
    [$user, $workspace, $channel] = channelPageSetup(channelPageWeekdaySchedule());
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'display_name' => 'Acme on X']);
    Post::factory()->forAccount($channel)->scheduled()->count(2)->create(['user_id' => $user->id]);
    Post::factory()->forAccount($channel)->draft()->create(['user_id' => $user->id]);
    Post::factory()->forAccount($channel)->published()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel))->resize(1440, 900);
    waitFor($page, '@channel-tiles');

    $page->assertSeeIn('@channel-name', 'Acme Corp')
        ->assertSeeIn('@channel-status', __('socialhq.channels.status.connected'))
        ->assertMissing('@channel-reconnect-banner')
        ->assertSeeIn('@channel-tile-scheduled-count', '2')
        ->assertSeeIn('@channel-tile-pending_approval-count', '0')
        ->assertSeeIn('@channel-tile-drafts-count', '1')
        ->assertSeeIn('@channel-tile-published-count', '1')
        ->assertSeeIn('@channel-week-progress', __('socialhq.channels.week.progress', ['sent' => 1, 'goal' => 3]))
        ->assertSeeIn('@channel-row-timezone-value', 'UTC')
        ->assertSeeIn('@channel-row-times-value', '9:00 AM')
        ->assertSeeIn('@channel-row-times-value', 'Monday to Friday')
        ->assertSeeIn('@channel-id-value', $channel->id)
        ->assertAttribute("@channel-side-item-{$channel->id}", 'aria-current', 'page')
        ->assertSeeIn("@channel-side-item-{$other->id}", 'Acme on X');

    $page->click("@channel-side-item-{$other->id}");
    waitForPath($page, route('app.channels.settings', $other, absolute: false));
    waitForText($page, '@channel-name', 'Acme on X');

    $page->assertAttribute("@channel-side-item-{$other->id}", 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});

test('posting times shared by every day are edited as a simple list', function () {
    [$user, , $channel] = channelPageSetup(channelPageWeekdaySchedule());
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel))->resize(1440, 900);
    waitFor($page, '@channel-times-edit');
    $page->click('@channel-times-edit');
    waitFor($page, '@channel-times-item-0900');
    $page->assertMissing('@channel-times-grid');

    $page->click('@channel-times-hour');
    waitFor($page, '@channel-times-hour-option-15');
    $page->click('@channel-times-hour-option-15');
    waitForGone($page, '@channel-times-hour-option-15');
    $page->click('@channel-times-add');
    waitFor($page, '@channel-times-item-1500');
    $page->click('@channel-times-remove-0900');
    waitForGone($page, '@channel-times-item-0900');
    $page->click('@channel-times-save');
    waitForGone($page, '@channel-times-dialog');
    waitForText($page, '@channel-row-times-value', '3:00 PM');

    $saved = null;

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $saved = $channel->fresh()->posting_schedule->days();

        if (data_get($saved, '1.times') === ['15:00']) {
            break;
        }

        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    expect(collect([1, 2, 3, 4, 5])->every(fn (int $day): bool => data_get($saved, "{$day}.times") === ['15:00']))->toBeTrue()
        ->and(data_get($saved, '0.times'))->toBe([])
        ->and(data_get($saved, '6.times'))->toBe([]);
    $page->assertNoJavaScriptErrors();
});

test('different times per day open the weekday grid straight away', function () {
    [$user, , $channel] = channelPageSetup(PostingSchedule::empty()->withTime(1, '09:00')->withTime(2, '14:30'));
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel))->resize(1440, 900);
    waitFor($page, '@channel-times-edit');
    $page->assertSeeIn('@channel-row-times-value', __('socialhq.channels.times.varied'))
        ->assertMissing('@channel-times-grid')
        ->click('@channel-times-edit');
    waitFor($page, '@channel-times-grid');

    $page->assertMissing('@channel-times-dialog')
        ->assertVisible('@schedule-day-2-time-1430')
        ->click('@channel-times-grid-hide');
    waitForGone($page, '@channel-times-grid');
    $page->assertNoJavaScriptErrors();
});

test('a channel gets a name of its own and can go back to the name from the network', function () {
    [$user, , $channel] = channelPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel))->resize(1440, 900);
    waitFor($page, '@channel-name-edit');
    $page->click('@channel-name-edit');
    waitFor($page, '@channel-name-input');
    $page->fill('@channel-name-input', 'Main page')->click('@channel-name-save');
    waitForGone($page, '@channel-name-dialog');
    waitForText($page, '@channel-name', 'Main page');

    $page->assertSeeIn("@channel-side-item-{$channel->id}", 'Main page');
    expect($channel->fresh()->shq_name)->toBe('Main page')
        ->and($channel->fresh()->display_name)->toBe('Acme Corp');

    $page->click('@channel-name-edit');
    waitFor($page, '@channel-name-input');
    $page->assertValue('@channel-name-input', 'Main page')
        ->fill('@channel-name-input', '')
        ->click('@channel-name-save');
    waitForGone($page, '@channel-name-dialog');
    waitForText($page, '@channel-name', 'Acme Corp');

    expect($channel->fresh()->shq_name)->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('a channel that lost its connection says so and offers to reconnect', function () {
    [$user, , $channel] = channelPageSetup();
    $channel->update(['status' => Status::Disconnected]);
    Post::factory()->forAccount($channel)->scheduled()->count(2)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel))->resize(1440, 900);
    waitFor($page, '@channel-reconnect-banner');

    $page->assertSeeIn('@channel-status', __('socialhq.channels.status.lost'))
        ->assertSeeIn('@channel-reconnect-banner', __('socialhq.channels.reconnect.title'))
        ->assertSeeIn('@channel-reconnect-banner', trans_choice('socialhq.channels.reconnect.body', 2, ['count' => 2, 'network' => 'LinkedIn']))
        ->assertVisible('@channel-reconnect')
        ->assertVisible("@channel-side-lost-{$channel->id}")
        ->assertNoJavaScriptErrors();
});

test('disconnect on the channel page opens the same dialog as the channel list', function () {
    [$user, , $channel] = channelPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel))->resize(1440, 900);
    waitFor($page, '@channel-disconnect');
    $page->click('@channel-disconnect');
    waitFor($page, '@confirm-delete-modal');

    $page->assertSeeIn('@disconnect-channel-title', 'Acme Corp')
        ->assertNoJavaScriptErrors();
    expect($channel->fresh())->not->toBeNull();
});

test('an admin copies a connect link for the account owner', function () {
    [$user, , $channel] = channelPageSetup();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $channel->workspace_id, 'display_name' => 'Acme on X']);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $x))->resize(1440, 900);
    waitFor($page, '@channel-invite-copy');
    $page->script('window.__copied = null; navigator.clipboard.writeText = async (text) => { window.__copied = text; };');
    $page->click('@channel-invite-copy');
    waitForCondition($page, 'typeof window.__copied === "string"');

    $invite = ConnectInvite::query()->sole();
    $copied = (string) $page->script('window.__copied');

    expect($invite->workspace_id)->toBe($channel->workspace_id)
        ->and($invite->platform)->toBe(Platform::X)
        ->and($invite->token_hash)->toBe(ConnectInvite::hash(str($copied)->afterLast('/')->value()))
        ->and($copied)->toStartWith(route('socialhq.connect-invite.landing', ''));
    $page->assertMissing('@channel-invite-dialog')->assertNoJavaScriptErrors();
});

test('when the browser refuses to copy, the connect link is shown instead', function () {
    [$user, , $channel] = channelPageSetup();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $channel->workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $x))->resize(1440, 900);
    waitFor($page, '@channel-invite-copy');
    $page->script('navigator.clipboard.writeText = async () => { throw new Error("denied"); };');
    $page->click('@channel-invite-copy');
    waitFor($page, '@channel-invite-url');

    $shown = (string) $page->script('document.querySelector(\'[data-testid="channel-invite-url"]\').value');

    expect(ConnectInvite::hash(str($shown)->afterLast('/')->value()))->toBe(ConnectInvite::query()->sole()->token_hash);
    $page->assertNoJavaScriptErrors();
});

test('a connect link opens a page that says who asks, and a used or wrong link says it no longer works', function () {
    [$user, $workspace, $channel] = channelPageSetup();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    [, $token] = ConnectInvite::issue($workspace, Platform::X, $user);

    $page = visit(route('socialhq.connect-invite.landing', $token))->resize(1280, 900);
    waitFor($page, '@connect-invite-continue');

    $page->assertSeeIn('@connect-invite-title', $workspace->name)
        ->assertSeeIn('@connect-invite', $user->name)
        ->assertSeeIn('@connect-invite-continue', Platform::X->label())
        ->assertNoJavaScriptErrors();

    $expired = visit(route('socialhq.connect-invite.landing', str_repeat('a', 48)))->resize(1280, 900);
    waitFor($expired, '@connect-invite-expired');
    $expired->assertSee(__('socialhq.connect_invite.expired.title'))->assertNoJavaScriptErrors();
});
