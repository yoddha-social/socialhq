<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

// ApprovalRules spots an agent by its Passport token. TryPostServer::actingAs()
// in the MCP tool tests carries none, so upstream's MCP tests never see our
// rules; this goes through the real endpoint with a real MCP grant.
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
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->mcpToken = mcpBearerToken($this->owner->fresh(), $this->workspace)['plain_token'];
});

/**
 * @param  array<string, mixed>  $arguments
 */
function socialHqMcpCall(object $test, string $token, string $tool, array $arguments): TestResponse
{
    return $test->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $arguments],
    ]);
}

test('a post an admin\'s mcp grant schedules waits for approval, and the grant cannot approve it', function () {
    socialHqMcpCall($this, $this->mcpToken, 'create-post-tool', [
        'content' => 'From an MCP agent over HTTP',
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'social_account_id' => $this->channel->id,
    ])->assertOk()->assertJsonPath('result.isError', false);

    $post = Post::query()->sole();
    expect($post->status)->toBe(PostStatus::PendingApproval);

    socialHqMcpCall($this, $this->mcpToken, 'approve-post-tool', ['post_id' => $post->id])
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect($post->fresh()->status)->toBe(PostStatus::PendingApproval);
});
