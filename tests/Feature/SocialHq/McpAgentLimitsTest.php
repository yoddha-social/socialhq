<?php

declare(strict_types=1);

use App\Enums\Repurpose\Status as RepurposeStatus;
use App\Models\AccessToken;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

// What an agent (an API key or MCP grant) may not do even with an admin's
// rights, through the real endpoints: TryPostServer::actingAs() carries no
// token, so the MCP tool tests never act as an agent.
beforeEach(function () {
    Queue::fake();
    config([
        'socialhq.approvals.members_always_reviewed' => true,
        'socialhq.approvals.agent_posts_reviewed' => true,
        'socialhq.agents.manage_admin_settings' => false,
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'source_social_account_id' => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id])->id,
    ]);
});

/**
 * @param  array<string, mixed>  $params
 */
function agentLimitsMcp(object $test, string $token, string $method, array $params = []): TestResponse
{
    return $test->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

/**
 * @param  array<string, mixed>  $arguments
 */
function agentLimitsTool(object $test, string $token, string $tool, array $arguments = []): TestResponse
{
    return agentLimitsMcp($test, $token, 'tools/call', ['name' => $tool, 'arguments' => $arguments])->assertOk();
}

test('an admin\'s mcp grant reads repurposes but cannot create or change them, and is told why', function () {
    $token = mcpBearerToken($this->owner->fresh(), $this->workspace)['plain_token'];

    agentLimitsTool($this, $token, 'list-repurposes-tool')
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.repurposes.0.id', $this->repurpose->id);
    agentLimitsTool($this, $token, 'get-repurpose-tool', ['repurpose_id' => $this->repurpose->id])->assertJsonPath('result.isError', false);
    agentLimitsTool($this, $token, 'list-repurpose-items-tool', ['repurpose_id' => $this->repurpose->id])->assertJsonPath('result.isError', false);
    agentLimitsTool($this, $token, 'list-repurpose-source-formats-tool')->assertJsonPath('result.isError', false);

    agentLimitsTool($this, $token, 'activate-repurpose-tool', ['repurpose_id' => $this->repurpose->id])
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', __('socialhq.agents.repurposes_read_only'));
    agentLimitsTool($this, $token, 'delete-repurpose-tool', ['repurpose_id' => $this->repurpose->id])
        ->assertJsonPath('result.isError', true);

    expect($this->repurpose->fresh()->status)->toBe(RepurposeStatus::Draft);
});

test('a member whose posts need approval cannot read repurposes through a grant either', function () {
    $member = workspaceMember($this->workspace, 'approval', ['current_workspace_id' => $this->workspace->id]);
    $token = mcpBearerToken($member, $this->workspace)['plain_token'];

    agentLimitsTool($this, $token, 'list-repurposes-tool')->assertJsonPath('result.isError', true);
});

test('the mcp server neither lists nor runs api key and webhook tools', function () {
    $token = mcpBearerToken($this->owner->fresh(), $this->workspace)['plain_token'];

    $names = collect(agentLimitsMcp($this, $token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->toContain('list-posts-tool', 'list-repurposes-tool')
        ->not->toContain('create-webhook-tool', 'list-webhooks-tool', 'replay-webhook-log-tool', 'create-api-key-tool', 'list-api-keys-tool');

    agentLimitsMcp($this, $token, 'tools/call', ['name' => 'create-webhook-tool', 'arguments' => ['endpoint' => 'https://example.com/hook', 'events' => ['post.published']]])
        ->assertJsonPath('error.message', 'Tool [create-webhook-tool] not found.');

    expect(Webhook::query()->count())->toBe(0);
});

test('an admin\'s api key cannot manage api keys or webhooks, while the admin still can in the app', function () {
    $token = passportToken($this->owner->fresh(), $this->workspace);
    $headers = parityApi($token);
    $tokensBefore = AccessToken::query()->count();

    $this->withHeaders($headers)->getJson(route('api.api-keys.index'))
        ->assertForbidden()
        ->assertJsonPath('message', __('socialhq.agents.admin_settings'));
    $this->withHeaders($headers)->postJson(route('api.api-keys.store'), ['name' => 'Exfiltration'])
        ->assertForbidden()
        ->assertJsonPath('message', __('socialhq.agents.admin_settings'));
    $this->withHeaders($headers)->postJson(route('api.webhooks.store'), ['endpoint' => 'https://example.com/hook', 'events' => ['post.published']])
        ->assertForbidden()
        ->assertJsonPath('message', __('socialhq.agents.admin_settings'));

    expect(AccessToken::query()->count())->toBe($tokensBefore)
        ->and(Webhook::query()->count())->toBe(0);

    // The usual API still works with that key, and a signed-in admin keeps both settings.
    $this->withHeaders($headers)->getJson(route('api.repurposes.index'))->assertOk();
    expect($this->owner->fresh()->can('manageApiKeys', $this->workspace))->toBeTrue()
        ->and($this->owner->fresh()->can('manageWebhooks', $this->workspace))->toBeTrue();
});

test('with the switch on, an admin\'s grant lists the api key and webhook tools again', function () {
    config(['socialhq.agents.manage_admin_settings' => true]);
    $token = mcpBearerToken($this->owner->fresh(), $this->workspace)['plain_token'];

    $names = collect(agentLimitsMcp($this, $token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->toContain('create-webhook-tool', 'create-api-key-tool');
});
