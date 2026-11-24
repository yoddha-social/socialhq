<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use Carbon\CarbonImmutable;

/*
 * The plan keeps an item's posts in step (user decision 2026-11-23): dropping
 * an item whose post will still go out asks what happens to that post, and
 * moving the item to another day offers to move its post too.
 */

/**
 * @return array{0: User, 1: PlanItem, 2: Post}
 */
function planItemWithScheduledPost(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $plan = ContentPlan::factory()->create(['workspace_id' => $workspace->id]);
    $item = PlanItem::factory()->create([
        'plan_id' => $plan->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform->value,
        'content_type' => ContentType::LinkedInPost,
        'creative' => CreativeKind::None,
    ]);
    $post = Post::factory()->forAccount($channel)->scheduled()->create([
        'user_id' => $user->id,
        'content' => 'Happy Dashain',
        'scheduled_at' => CarbonImmutable::parse("{$item->scheduled_on->toDateString()} 10:00", 'UTC'),
    ]);
    ItemPosts::link($item, collect([$post]), $user);

    return [$user->fresh(), $item, $post];
}

function openPlanItemSheet(PlanItem $item): mixed
{
    $page = visit(route('socialhq.plan.show', PlanMonth::key($item->plan->month->toImmutable())))->resize(1440, 900);
    waitFor($page, "@plan-item-open-{$item->id}");
    $page->click("@plan-item-open-{$item->id}");
    waitFor($page, '@plan-item-sheet');

    return $page;
}

test('dropping an item whose post is scheduled asks first, and can send the post to drafts', function () {
    [$user, $item, $post] = planItemWithScheduledPost();
    $this->actingAs($user);

    $page = openPlanItemSheet($item);
    $page->click('@plan-item-toggle-drop');
    waitFor($page, '@plan-posts-choice');
    $page->assertSeeIn('@plan-posts-choice', __('socialhq_plan.posts_choice.drop_title'))
        ->assertVisible("@plan-posts-choice-post-{$post->id}");

    // Cancelling drops nothing.
    $page->click('@plan-posts-choice-cancel');
    waitForGone($page, '@plan-posts-choice');
    expect($item->fresh()->status)->toBe(ItemStatus::Planned)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);

    // "Move it to drafts" is the default.
    $page->click('@plan-item-toggle-drop');
    waitFor($page, '@plan-posts-choice');
    waitForState($page, '@plan-posts-choice-drafts', "element.getAttribute('aria-checked') === 'true'");
    $page->click('@plan-posts-choice-confirm');
    waitForDatabase($page, fn (): bool => $item->fresh()->status === ItemStatus::Dropped);

    expect($item->fresh()->status)->toBe(ItemStatus::Dropped)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
    $page->assertNoJavaScriptErrors();
});

test('moving an item to another day offers to move its post too', function () {
    [$user, $item, $post] = planItemWithScheduledPost();
    $this->actingAs($user);
    $newDay = $item->scheduled_on->addDays(2)->toDateString();

    $page = openPlanItemSheet($item);
    $page->select('@plan-item-day', $newDay);
    $page->click('@plan-item-save');
    waitForDatabase($page, fn (): bool => $item->fresh()->scheduled_on->toDateString() === $newDay);
    expect($item->fresh()->scheduled_on->toDateString())->toBe($newDay);

    waitForText($page, '[data-sonner-toast]', trans_choice('socialhq_plan.posts_follow.offer', 1));
    $page->click('[data-sonner-toast] [data-button]');
    waitForDatabase($page, fn (): bool => $post->fresh()->scheduled_at->toDateString() === $newDay);

    expect($post->fresh()->scheduled_at->format('Y-m-d H:i'))->toBe("{$newDay} 10:00")
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
    $page->assertNoJavaScriptErrors();
});
