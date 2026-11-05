<?php

declare(strict_types=1);

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\UpdatePost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Approval\ApprovalEvent;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\AccessToken;

beforeEach(function () {
    Queue::fake();
    config(['socialhq.approvals.members_always_reviewed' => true, 'socialhq.approvals.agent_posts_reviewed' => true]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->member = workspaceMember($this->workspace, 'member');
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
});

function approvalEventsRequest(object $test, User $by): Post
{
    $test->actingAs($by);

    return CreatePosts::execute($test->workspace, $by, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Approval history',
        'media' => [],
        'destinations' => [['social_account_id' => $test->channel->id, 'content_type' => 'linkedin_post', 'meta' => []]],
    ])->sole();
}

/**
 * @return list<array{0: string, 1: string|null, 2: string}>
 */
function approvalEventsOf(Post $post): array
{
    return ApprovalEvent::query()->where('post_id', $post->id)->orderBy('id')->get()
        ->map(fn (ApprovalEvent $event): array => [$event->action, $event->user_id, $event->via])
        ->all();
}

test('a request and its approval are recorded with who did them', function () {
    $post = approvalEventsRequest($this, $this->member);

    $this->actingAs($this->owner);
    ApprovePost::execute($post, $this->owner, []);

    expect(approvalEventsOf($post))->toBe([
        ['requested', $this->member->id, 'app'],
        ['approved', $this->owner->id, 'app'],
    ]);
});

test('a rejection is recorded, unlike in TryPost', function () {
    $post = approvalEventsRequest($this, $this->member);

    $this->actingAs($this->owner);
    RejectPost::execute($post, $this->owner);

    expect(approvalEventsOf($post)[1])->toBe(['rejected', $this->owner->id, 'app'])
        ->and(ApprovalEvent::query()->where('post_id', $post->id)->latest('id')->first()->to_status)->toBe('draft');
});

test('the requester moving the post back to drafts is a withdrawal', function () {
    $post = approvalEventsRequest($this, $this->member);

    UpdatePost::execute($this->workspace, $post->fresh(), ['status' => 'draft'], $this->member);

    expect(approvalEventsOf($post)[1])->toBe(['withdrawn', $this->member->id, 'app']);
});

test('requests made through an api key or mcp grant are marked as agent requests', function () {
    $agent = $this->owner->withAccessToken(new AccessToken(['oauth_access_token_id' => 'agent-token']));
    $this->actingAs($agent, 'api');

    $post = approvalEventsRequest($this, $agent);

    expect(approvalEventsOf($post))->toBe([['requested', $this->owner->id, 'agent']]);
});

test('saves that do not touch approval leave no history', function () {
    $this->actingAs($this->owner);
    $post = approvalEventsRequest($this, $this->owner);

    expect(ApprovalEvent::query()->where('post_id', $post->id)->exists())->toBeFalse();
});
