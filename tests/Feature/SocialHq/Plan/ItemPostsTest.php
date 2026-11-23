<?php

declare(strict_types=1);

use App\Actions\Post\BuildPublishPageProps;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Http\Resources\App\PostCardResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $this->account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create([
        'plan_id' => $this->plan->id,
        'social_account_id' => $this->account->id,
        'creative' => CreativeKind::None,
        'copy_status' => CopyStatus::Approved,
    ]);
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'Happy Dashain']);
});

/**
 * What the composer sends when it saves a draft for the item's channel.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function itemPostPayload(SocialAccount $account, array $overrides = []): array
{
    return [
        'status' => PostStatus::Draft->value,
        'content' => 'Happy Dashain',
        'media' => [],
        'destinations' => [['social_account_id' => $account->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []]],
        ...$overrides,
    ];
}

test('opening the composer leaves the item as it was; saving the post links it', function () {
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.post.store', $this->item->id))->assertOk();

    expect($this->item->fresh()->post_created_at)->toBeNull();
    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn ($page) => $page->where('itemPosts', []));

    $this->actingAs($this->owner)->post(route('app.posts.store'), itemPostPayload($this->account, ['plan_item_id' => $this->item->id]))
        ->assertSessionHasNoErrors();

    $post = Post::query()->sole();

    expect($this->item->fresh()->post_created_at)->not->toBeNull()
        ->and($this->item->posts()->pluck('posts.id')->all())->toBe([$post->id]);

    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn ($page) => $page->where('itemPosts.0.id', $post->id)
            ->where('itemPosts.0.status', PostStatus::Draft->value)
            ->where('itemPosts.0.url', route('app.posts.edit', $post)));

    $this->actingAs($this->owner)->get(route('socialhq.plan.show', PlanMonth::key($this->plan->month->toImmutable())))
        ->assertInertia(fn ($page) => $page->where('items.0.post_status', PostStatus::Draft->value));
});

test('a post saved without an item is not linked to one', function () {
    $this->actingAs($this->owner)->post(route('app.posts.store'), itemPostPayload($this->account))->assertSessionHasNoErrors();

    expect(Post::query()->count())->toBe(1)
        ->and($this->item->posts()->count())->toBe(0)
        ->and($this->item->fresh()->post_created_at)->toBeNull();
});

test('an item of another workspace is refused and nothing is saved', function () {
    $other = PlanItem::factory()->create();

    $this->actingAs($this->owner)->post(route('app.posts.store'), itemPostPayload($this->account, ['plan_item_id' => $other->id]))
        ->assertSessionHasErrors('plan_item_id');

    expect(Post::query()->count())->toBe(0)
        ->and($other->posts()->count())->toBe(0);
});

test('a deleted post drops off the item', function () {
    $this->actingAs($this->owner)->post(route('app.posts.store'), itemPostPayload($this->account, ['plan_item_id' => $this->item->id]));
    Post::query()->sole()->delete();

    expect($this->item->posts()->count())->toBe(0);
    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn ($page) => $page->where('itemPosts', []));
});

test('a post card names the plan item the post was made from', function () {
    $this->actingAs($this->owner)->post(route('app.posts.store'), itemPostPayload($this->account, ['plan_item_id' => $this->item->id]));
    $this->actingAs($this->owner)->post(route('app.posts.store'), itemPostPayload($this->account, ['content' => 'Not from the plan']));

    $cards = collect(PostCardResource::cards(BuildPublishPageProps::cardQuery($this->workspace->posts(), [], false)->get()))->keyBy('content');

    expect($cards['Happy Dashain']['plan_item'])->toBe(['id' => $this->item->id, 'title' => $this->item->title, 'url' => route('socialhq.plan.items.show', $this->item->id)])
        ->and($cards['Not from the plan']['plan_item'])->toBeNull();
});

test('an item shows a failed post first, then the one furthest along', function () {
    expect(ItemPosts::shownStatus([]))->toBeNull()
        ->and(ItemPosts::shownStatus([PostStatus::Draft, PostStatus::Scheduled]))->toBe('scheduled')
        ->and(ItemPosts::shownStatus(['published', 'draft']))->toBe('published')
        ->and(ItemPosts::shownStatus([PostStatus::Published, PostStatus::Failed]))->toBe('failed');
});
