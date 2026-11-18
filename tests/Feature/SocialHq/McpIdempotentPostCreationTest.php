<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->key = (string) Str::uuid();
});

test('create-post-tool called again with the same key returns the first post instead of a duplicate', function () {
    $arguments = ['content' => 'Launch day', 'social_account_id' => $this->channel->id, 'idempotency_key' => $this->key];

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $arguments)->assertOk()->assertHasNoErrors();
    $post = Post::query()->sole();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $arguments)
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('id', $post->id)->where('content', 'Launch day')->etc());

    expect(Post::query()->count())->toBe(1);
});

test('a key reused with different arguments creates nothing and says so', function () {
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, ['content' => 'First', 'social_account_id' => $this->channel->id, 'idempotency_key' => $this->key])->assertHasNoErrors();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, ['content' => 'Second', 'social_account_id' => $this->channel->id, 'idempotency_key' => $this->key])
        ->assertHasErrors([__('socialhq.agents.idempotency_key_reused')]);

    expect(Post::query()->pluck('content')->all())->toBe(['First']);
});

test('a retry after the post was deleted says so instead of creating it again', function () {
    $arguments = ['content' => 'Gone', 'social_account_id' => $this->channel->id, 'idempotency_key' => $this->key];

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $arguments)->assertHasNoErrors();
    Post::query()->sole()->delete();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $arguments)
        ->assertHasErrors([__('socialhq.agents.idempotency_key_deleted')]);

    expect(Post::query()->count())->toBe(0);
});

test('a call that failed keeps nothing, so the same key runs again', function () {
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, ['content' => 'No channel', 'idempotency_key' => $this->key])->assertHasErrors();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, ['content' => 'Fixed', 'social_account_id' => $this->channel->id, 'idempotency_key' => $this->key])
        ->assertHasNoErrors();

    expect(Post::query()->pluck('content')->all())->toBe(['Fixed']);
});

test('create-posts-tool called again with the same key returns the same batch', function () {
    $second = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $arguments = [
        'status' => 'draft',
        'content' => 'Batch',
        'destinations' => [['social_account_id' => $this->channel->id], ['social_account_id' => $second->id]],
        'idempotency_key' => $this->key,
    ];

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $arguments)->assertHasNoErrors();
    $ids = Post::query()->orderBy('created_at')->pluck('id');

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $arguments)
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('posts', 2)
            ->where('posts', fn ($posts) => collect($posts)->pluck('id')->sort()->values()->all() === $ids->sort()->values()->all()));

    expect(Post::query()->count())->toBe(2);
});

test('without a key, and for another person with the same key, each call creates its post', function () {
    $arguments = ['content' => 'Twice', 'social_account_id' => $this->channel->id];

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $arguments)->assertHasNoErrors();
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, $arguments)->assertHasNoErrors();

    $colleague = workspaceMember($this->workspace, 'admin', ['current_workspace_id' => $this->workspace->id]);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [...$arguments, 'idempotency_key' => $this->key])->assertHasNoErrors();
    TryPostServer::actingAs($colleague)->tool(CreatePostTool::class, [...$arguments, 'idempotency_key' => $this->key])->assertHasNoErrors();

    expect(Post::query()->count())->toBe(4);
});
