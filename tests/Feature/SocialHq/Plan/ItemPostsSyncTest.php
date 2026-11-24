<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use Carbon\CarbonImmutable;

/**
 * The plan keeps an item's posts in step: dropping or deleting an item whose
 * post will still go out needs a choice (back to drafts, or keep it
 * scheduled), and an item moved to another day can take its posts along.
 */
beforeEach(function () {
    $this->owner = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $this->account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'Asia/Kathmandu']);
    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create([
        'plan_id' => $this->plan->id,
        'social_account_id' => $this->account->id,
        'creative' => CreativeKind::None,
        'copy_status' => CopyStatus::Approved,
    ]);
});

/**
 * A post of the item's channel at a wall clock time in the channel's zone, linked to the item.
 */
function itemPostAt(PlanItem $item, SocialAccount $account, User $user, string $state, string $day, string $time = '10:00'): Post
{
    $post = Post::factory()->forAccount($account)->{$state}()->create([
        'user_id' => $user->id,
        'content' => 'Happy Dashain',
        'scheduled_at' => CarbonImmutable::parse("{$day} {$time}", 'Asia/Kathmandu')->utc(),
    ]);

    ItemPosts::link($item, collect([$post]), $user);

    return $post;
}

test('dropping an item whose post is scheduled asks what happens to the post first', function () {
    $post = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString());

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.drop', $this->item->id))
        ->assertSessionHasErrors('posts');

    expect($this->item->fresh()->status)->toBe(ItemStatus::Planned)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);

    $this->actingAs($this->owner)->get(route('socialhq.plan.show', PlanMonth::key($this->plan->month->toImmutable())))
        ->assertInertia(fn ($page) => $page->where('items.0.upcoming_posts.0.id', $post->id)
            ->where('items.0.upcoming_posts.0.status', PostStatus::Scheduled->value));
});

test('dropping an item can send its scheduled posts back to drafts', function () {
    $post = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString());
    $second = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString(), '15:00');

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.drop', $this->item->id), ['posts' => ItemPosts::TO_DRAFTS])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->item->fresh()->status)->toBe(ItemStatus::Dropped)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft)
        ->and($second->fresh()->status)->toBe(PostStatus::Draft);
});

test('dropping an item can keep its post scheduled', function () {
    $post = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString());

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.drop', $this->item->id), ['posts' => ItemPosts::KEEP])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->item->fresh()->status)->toBe(ItemStatus::Dropped)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('deleting an item asks about its post and can send it back to drafts', function () {
    $post = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString());

    $this->actingAs($this->owner)->delete(route('socialhq.plan.items.destroy', $this->item->id))
        ->assertSessionHasErrors('posts');
    expect(PlanItem::query()->find($this->item->id))->not->toBeNull();

    $this->actingAs($this->owner)->delete(route('socialhq.plan.items.destroy', $this->item->id), ['posts' => ItemPosts::TO_DRAFTS])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(PlanItem::query()->find($this->item->id))->toBeNull()
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('an item whose posts are drafts or published drops without asking', function () {
    $draft = itemPostAt($this->item, $this->account, $this->owner, 'draft', $this->item->scheduled_on->toDateString());
    $published = itemPostAt($this->item, $this->account, $this->owner, 'published', $this->item->scheduled_on->toDateString());

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.drop', $this->item->id))
        ->assertSessionHasNoErrors();

    expect($this->item->fresh()->status)->toBe(ItemStatus::Dropped)
        ->and($draft->fresh()->status)->toBe(PostStatus::Draft)
        ->and($published->fresh()->status)->toBe(PostStatus::Published);
});

test('a post waiting for approval goes back to drafts with the item', function () {
    $post = itemPostAt($this->item, $this->account, $this->owner, 'pendingApproval', $this->item->scheduled_on->toDateString());

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.drop', $this->item->id), ['posts' => ItemPosts::TO_DRAFTS])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('an item moved to another day can take its posts along at the same time of day', function () {
    $oldDay = $this->item->scheduled_on->toDateString();
    $scheduled = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $oldDay, '18:30');
    $draft = itemPostAt($this->item, $this->account, $this->owner, 'draft', $oldDay, '09:15');

    $newDay = $this->item->scheduled_on->addDays(3)->toDateString();
    $this->item->update(['scheduled_on' => $newDay]);

    $month = PlanMonth::key($this->plan->month->toImmutable());
    $this->actingAs($this->owner)->get(route('socialhq.plan.show', $month))
        ->assertInertia(fn ($page) => $page->where('items.0.posts_off_day', 2));
    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn ($page) => $page->where('itemPosts.0.off_day', true));

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.post.follow', $this->item->id))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $local = fn (Post $post): string => CarbonImmutable::parse($post->fresh()->scheduled_at)->setTimezone('Asia/Kathmandu')->format('Y-m-d H:i');

    expect($local($scheduled))->toBe("{$newDay} 18:30")
        ->and($scheduled->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($local($draft))->toBe("{$newDay} 09:15")
        ->and($draft->fresh()->status)->toBe(PostStatus::Draft);

    $this->actingAs($this->owner)->get(route('socialhq.plan.show', $month))
        ->assertInertia(fn ($page) => $page->where('items.0.posts_off_day', 0));
});

test('the item\'s day is read in its posting time\'s zone', function () {
    // 23:30 in Kathmandu is 17:45 UTC the same day, and the next day in Sydney.
    itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString(), '23:30');

    expect(ItemPosts::offDayCount($this->item->fresh()->load('posts.socialAccount')))->toBe(0);

    $this->item->update(['suggested_time' => '09:00', 'suggested_timezone' => 'Australia/Sydney']);

    expect(ItemPosts::offDayCount($this->item->fresh()->load('posts.socialAccount')))->toBe(1);
});

test('a post about to go out keeps its time and is named', function () {
    $post = Post::factory()->forAccount($this->account)->scheduled()->create([
        'user_id' => $this->owner->id,
        'scheduled_at' => now()->addSeconds(30),
    ]);
    ItemPosts::link($this->item, collect([$post]), $this->owner);
    $due = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.post.follow', $this->item->id))
        ->assertSessionHasErrors(['posts' => trans_choice('socialhq_plan.posts_follow.kept', 1, ['count' => 1])]);

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe($due);
});
