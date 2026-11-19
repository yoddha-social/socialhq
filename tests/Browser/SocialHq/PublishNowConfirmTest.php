<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

/*
 * QA 2026-11-18 P1-3: on a real channel one misclick on "Publish now" posted
 * at once. It now asks first (phpunit.xml turns this off for TryPost's tests).
 */

beforeEach(function () {
    config(['socialhq.publish_now.confirm' => true]);
});

/**
 * @return array{0: User, 1: Post}
 */
function publishNowScheduledPost(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $post = Post::factory()->forAccount($channel)->scheduled()->create(['user_id' => $user->id, 'content' => 'Scheduled for later']);

    return [$user->fresh(), $post];
}

function openPublishNowFromMenu(mixed $page, Post $post): void
{
    waitFor($page, "@post-card-menu-{$post->id}");
    $page->click("@post-card-menu-{$post->id}");
    waitFor($page, "@post-publish-now-{$post->id}");
    $page->click("@post-publish-now-{$post->id}");
    waitFor($page, '@publish-now-confirm');
}

test('publish now from a post\'s menu asks first, and cancelling keeps it scheduled', function () {
    Queue::fake([PublishPost::class]);
    [$user, $post] = publishNowScheduledPost();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));

    // The card itself no longer has a publish now button.
    waitFor($page, "@post-edit-{$post->id}");
    expect($page->script("document.querySelectorAll('[data-testid=\"post-publish-now-{$post->id}\"]').length"))->toBe(0);

    openPublishNowFromMenu($page, $post);
    $page->assertSeeIn('@publish-now-confirm', __('socialhq.publish_now.title'))
        ->click('@publish-now-cancel');
    waitForGone($page, '@publish-now-confirm');

    // Escape closes it the same way.
    openPublishNowFromMenu($page, $post);
    $page->keys('@publish-now-confirm', 'Escape');
    waitForGone($page, '@publish-now-confirm');

    // Neither sent a request: the browser saw no call to the post's schedule
    // endpoint (the Queue page itself is /schedule, so match the post's URL).
    expect($page->script("performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/posts/{$post->id}/schedule')).length"))->toBe(0)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);

    openPublishNowFromMenu($page, $post);
    $page->click('@publish-now-confirm-button');
    waitForDatabase($page, fn (): bool => $post->fresh()->status === PostStatus::Publishing);

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    $page->assertNoJavaScriptErrors();
});
