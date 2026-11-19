<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Notices\Notice;
use App\SocialHq\Notices\NoticeKind;
use App\SocialHq\Notices\NoticePreference;

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount, 3: Post}
 */
function noticeBellSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $post = Post::factory()->forAccount($channel)->create(['user_id' => $user->id, 'status' => PostStatus::Draft, 'scheduled_at' => null]);

    return [$user->fresh(), $workspace, $channel, $post];
}

/**
 * @param  array<string, mixed>  $data
 */
function noticeBellNotice(User $user, Workspace $workspace, NoticeKind $kind, array $data, ?Post $post = null, ?SocialAccount $channel = null): Notice
{
    return Notice::query()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'kind' => $kind,
        'post_id' => $post?->id,
        'social_account_id' => $channel?->id,
        'data' => $data,
    ]);
}

test('the bell counts unread notices, lists them with their button, and mark all read clears the count', function () {
    [$user, $workspace, $channel, $post] = noticeBellSetup();
    $failed = noticeBellNotice($user, $workspace, NoticeKind::PostFailed, ['channel' => 'Brew Co', 'excerpt' => 'Winter menu', 'reason' => 'The channel lost its connection.', 'reconnect' => true], $post, $channel);
    $note = noticeBellNotice($user, $workspace, NoticeKind::PostNote, ['channel' => 'Brew Co', 'actor' => 'Asha Gurung', 'note' => 'Swap the second photo?'], $post, $channel);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week']))->resize(1440, 900);
    waitFor($page, '@notice-bell-count');
    $page->assertSeeIn('@notice-bell-count', '2')
        ->assertMissing('@notice-panel')
        ->click('@notice-bell');
    waitFor($page, "@notice-{$failed->id}");

    $page->assertSeeIn("@notice-{$failed->id}", __('socialhq.notices.kinds.post_failed.title', ['channel' => 'Brew Co']))
        ->assertSeeIn("@notice-{$failed->id}", 'The channel lost its connection.')
        ->assertSeeIn("@notice-{$failed->id}", __('socialhq.notices.actions.reconnect'))
        ->assertSeeIn("@notice-{$note->id}", 'Swap the second photo?')
        ->click('@notice-mark-all-read');
    waitForGone($page, '@notice-bell-count');
    waitForState($page, "@notice-{$failed->id}", 'element.hasAttribute("data-read")');

    expect(Notice::query()->whereNull('read_at')->count())->toBe(0);
    $page->assertMissing('@notice-mark-all-read')->assertNoJavaScriptErrors();
});

test('a notice\'s button opens what it is about and marks that notice read', function () {
    [$user, $workspace, $channel, $post] = noticeBellSetup();
    $note = noticeBellNotice($user, $workspace, NoticeKind::PostNote, ['channel' => 'Brew Co', 'actor' => 'Asha Gurung', 'note' => 'Swap the second photo?'], $post, $channel);
    $other = noticeBellNotice($user, $workspace, NoticeKind::ChannelDisconnected, ['channel' => 'Brew Co'], null, $channel);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week']))->resize(1440, 900);
    waitFor($page, '@notice-bell');
    $page->click('@notice-bell');
    waitFor($page, "@notice-action-{$note->id}");
    $page->click("@notice-action-{$note->id}");
    waitForPath($page, route('app.posts.edit', $post->id, false));

    expect($note->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('with nothing to show the bell has no count and says so', function () {
    [$user] = noticeBellSetup();
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week']))->resize(1440, 900);
    waitFor($page, '@notice-bell');
    $page->assertMissing('@notice-bell-count')->click('@notice-bell');
    waitFor($page, '@notice-empty');

    $page->assertSeeIn('@notice-empty', __('socialhq.notices.empty_title'))->assertNoJavaScriptErrors();
});

test('the "In the app" switch of a row saves on its own and leaves the email switch alone', function () {
    [$user] = noticeBellSetup();
    $this->actingAs($user);

    $page = visit(route('app.notifications.preferences'));
    waitForState($page, '@notifications-app-post_published', '!element.disabled');
    $page->assertAttribute('@notifications-app-post_published', 'data-state', 'checked')
        ->click('@notifications-app-post_published');
    waitForState($page, '@notifications-app-post_published', "element.dataset.state === 'unchecked'");
    $page->click('@notifications-live');
    waitForState($page, '@notifications-live', "element.dataset.state === 'unchecked'");

    $page = visit(route('app.notifications.preferences'));
    waitForState($page, '@notifications-app-post_published', '!element.disabled');
    $page->assertAttribute('@notifications-app-post_published', 'data-state', 'unchecked')
        ->assertAttribute('@notifications-app-post_failed', 'data-state', 'checked')
        ->assertAttribute('@notifications-post_published', 'data-state', 'checked')
        ->assertAttribute('@notifications-live', 'data-state', 'unchecked')
        ->assertNoJavaScriptErrors();

    $stored = NoticePreference::query()->where('user_id', $user->id)->sole();

    expect($stored->in_app)->toEqual(['post_published' => false])
        ->and($stored->live)->toBeFalse();
});
