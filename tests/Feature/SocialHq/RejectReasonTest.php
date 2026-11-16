<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\SendNotification;
use App\Mail\PostRejected;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['socialhq.approvals.members_always_reviewed' => true]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->member = workspaceMember($this->workspace, 'member');
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
});

function rejectReasonRequest(object $test): Post
{
    return CreatePosts::execute($test->workspace, $test->member, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Please approve',
        'media' => [],
        'destinations' => [['social_account_id' => $test->channel->id, 'content_type' => 'linkedin_post', 'meta' => []]],
    ])->sole();
}

test('a reason given when rejecting becomes the approver\'s note and goes in the email', function () {
    $post = rejectReasonRequest($this);

    $this->actingAs($this->owner)
        ->put(route('app.posts.reject', $post), ['reason' => '  Add the price <b>and</b> where to buy.  '])
        ->assertRedirect();

    $post->refresh();
    $note = $post->notes()->sole();

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($note->user_id)->toBe($this->owner->id)
        ->and($note->body)->toBe('Add the price <b>and</b> where to buy.');

    Queue::assertPushed(SendNotification::class, function (SendNotification $notification) use ($post): bool {
        if (! $notification->mailable instanceof PostRejected || ! $notification->user->is($this->member)) {
            return false;
        }

        $notification->mailable->assertSeeInHtml(__('mail.post_rejected.reason'));
        $notification->mailable->assertSeeInHtml('Add the price &lt;b&gt;and&lt;/b&gt; where to buy.', false);

        return $notification->mailable->postId === $post->id;
    });
});

test('rejecting without a reason adds no note and the email has no reason', function () {
    $post = rejectReasonRequest($this);

    $this->actingAs($this->owner)->put(route('app.posts.reject', $post), ['reason' => '   '])->assertRedirect();

    expect($post->notes()->exists())->toBeFalse();

    Queue::assertPushed(SendNotification::class, function (SendNotification $notification): bool {
        if (! $notification->mailable instanceof PostRejected) {
            return false;
        }

        $notification->mailable->assertDontSeeInHtml(__('mail.post_rejected.reason'));

        return true;
    });
});

test('a reason longer than a note is refused and the post stays pending', function () {
    $post = rejectReasonRequest($this);

    $this->actingAs($this->owner)
        ->put(route('app.posts.reject', $post), ['reason' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('reason');

    expect($post->refresh()->status)->toBe(PostStatus::PendingApproval);
});

test('a member who needs approval cannot reject, with or without a reason', function () {
    $post = rejectReasonRequest($this);

    $this->actingAs($this->member)
        ->put(route('app.posts.reject', $post), ['reason' => 'no'])
        ->assertForbidden();

    expect($post->refresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($post->notes()->exists())->toBeFalse();
});
