<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\SendNotification;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\ApprovePostTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Models\Invite;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Approval\ApprovalRules;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\AccessToken;

beforeEach(function () {
    Queue::fake();
    config([
        'socialhq.approvals.members_always_reviewed' => true,
        'socialhq.approvals.agent_posts_reviewed' => true,
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
});

/**
 * @return array<string, mixed>
 */
function socialHqScheduledPost(SocialAccount $channel): array
{
    return [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Social HQ approval rules',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ];
}

/**
 * Marks the user as calling through a Passport token, as API keys and MCP
 * grants do (TryPostServer::actingAs alone authenticates without a token).
 */
function actingAsAgent(User $user): User
{
    return $user->withAccessToken(new AccessToken(['oauth_access_token_id' => 'agent-token', 'oauth_scopes' => ['mcp:use']]));
}

test('a member without the approval flag still needs approval', function () {
    $member = workspaceMember($this->workspace, 'member');

    $post = CreatePosts::execute($this->workspace, $member, socialHqScheduledPost($this->channel))->sole();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($member->accessIn($this->workspace)['requires_approval'])->toBeTrue()
        ->and($member->canPublishDirectlyIn($this->workspace))->toBeFalse()
        ->and($this->workspace->approvers()->pluck('id'))->not->toContain($member->id);
});

test('an admin in the app publishes directly and approves', function () {
    $admin = workspaceMember($this->workspace, 'admin');

    $post = CreatePosts::execute($this->workspace, $admin, socialHqScheduledPost($this->channel))->sole();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($admin->canPublishDirectlyIn($this->workspace))->toBeTrue()
        ->and($this->workspace->approvers()->pluck('id'))->toContain($admin->id);
});

test('posts from an admin api key wait for approval, and the key cannot approve them', function () {
    $headers = ['Authorization' => 'Bearer '.passportToken($this->owner, $this->workspace)];

    $id = $this->withHeaders($headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Written by an agent',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'social_account_id' => $this->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
        ])
        ->assertCreated()
        ->assertJsonPath('status', PostStatus::PendingApproval->value)
        ->json('id');

    $this->withHeaders($headers)
        ->postJson(route('api.posts.approve', $id))
        ->assertForbidden();

    expect(Post::find($id)->status)->toBe(PostStatus::PendingApproval);
});

test('an agent request emails the admin who owns the key and the admin approves it in the app', function () {
    $agent = actingAsAgent($this->owner);
    $this->actingAs($agent, 'api');

    $post = CreatePosts::execute($this->workspace, $agent, socialHqScheduledPost($this->channel))->sole();

    expect($post->status)->toBe(PostStatus::PendingApproval);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->user->is($this->owner));

    $human = User::find($this->owner->id);
    $this->actingAs($human, 'web')
        ->put(route('app.posts.approve', $post))
        ->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($post->fresh()->approved_by)->toBe($this->owner->id);
});

test('posts written through mcp wait for approval and mcp cannot approve', function () {
    $agent = actingAsAgent($this->owner);

    TryPostServer::actingAs($agent)->tool(CreatePostTool::class, [
        'content' => 'From an MCP agent',
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'social_account_id' => $this->channel->id,
    ])->assertOk();

    $post = Post::query()->sole();
    expect($post->status)->toBe(PostStatus::PendingApproval);

    TryPostServer::actingAs($agent)
        ->tool(ApprovePostTool::class, ['post_id' => $post->id])
        ->assertHasErrors(['This action is unauthorized.']);

    expect($post->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('new members and invites are stored as needing approval', function () {
    $this->actingAs($this->owner)
        ->post(route('app.invites.store'), ['email' => 'writer@example.com', ...membershipPivot('member')])
        ->assertRedirect();

    expect(Invite::query()->where('email', 'writer@example.com')->sole()->requires_approval)->toBeTrue();
});

test('the migration flags existing members and pending invites who are not admins', function () {
    $member = workspaceMember($this->workspace, 'member');
    $admin = workspaceMember($this->workspace, 'admin');

    $migration = require database_path('migrations/socialhq/2026_11_05_000001_review_every_member_who_is_not_an_admin.php');
    $migration->up();

    $pivot = fn (User $user) => $this->workspace->members()->where('users.id', $user->id)->sole()->pivot;

    expect((bool) $pivot($member)->requires_approval)->toBeTrue()
        ->and((bool) $pivot($admin)->requires_approval)->toBeFalse();
});

test('with the rules off, TryPost\'s own behaviour is back', function () {
    config([
        'socialhq.approvals.members_always_reviewed' => false,
        'socialhq.approvals.agent_posts_reviewed' => false,
    ]);
    $member = workspaceMember($this->workspace, 'member');
    $agent = actingAsAgent($this->owner);
    $this->actingAs($agent, 'api');

    expect(CreatePosts::execute($this->workspace, $member, socialHqScheduledPost($this->channel))->sole()->status)->toBe(PostStatus::Scheduled)
        ->and(CreatePosts::execute($this->workspace, $agent, socialHqScheduledPost($this->channel))->sole()->status)->toBe(PostStatus::Scheduled)
        ->and(ApprovalRules::actsAsAgent($agent))->toBeFalse();
});

test('web sessions, jobs and other users are never treated as agents', function () {
    expect(ApprovalRules::actsAsAgent($this->owner))->toBeFalse();

    $this->actingAs($this->owner);
    expect(ApprovalRules::actsAsAgent($this->owner))->toBeFalse();

    $agent = actingAsAgent(workspaceMember($this->workspace, 'admin'));
    $this->actingAs($agent, 'api');
    expect(ApprovalRules::actsAsAgent($this->owner))->toBeFalse()
        ->and(ApprovalRules::actsAsAgent($agent))->toBeTrue();
});

test('pages tell the frontend whether members are always reviewed', function () {
    $this->actingAs($this->owner)
        ->get(route('app.members'))
        ->assertInertia(fn ($page) => $page->where('socialhq.approvals.membersAlwaysReviewed', true));
});
