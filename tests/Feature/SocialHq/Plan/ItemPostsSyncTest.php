<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\SendNotification;
use App\Mail\PostApprovalRequested;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Approval\ApprovalEvent;
use App\SocialHq\Notices\Notice;
use App\SocialHq\Notices\NoticeKind;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemPosts;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

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
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.error', trans_choice('socialhq_plan.posts_follow.kept', 1, ['count' => 1]))
        ->assertSessionMissing('flash.success')
        ->assertSessionMissing('flash.warning');

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe($due)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

/**
 * The item's day as the "waiting for approval" toast names it.
 */
function followDay(PlanItem $item): string
{
    return $item->fresh()->scheduled_on->locale('en')->isoFormat('ddd D MMM');
}

test('a member is warned before moving an approved post, and told after that it waits for approval', function () {
    // X13: members' changes to scheduled posts always need approval (production rules), so the
    // owner's approved post goes back for approval on its new day; nobody may think it's done.
    $member = workspaceMember($this->workspace, 'member');
    $oldDay = $this->item->scheduled_on->toDateString();
    $approved = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $oldDay, '18:30');
    $draft = itemPostAt($this->item, $this->account, $this->owner, 'draft', $oldDay, '09:15');
    $newDay = $this->item->scheduled_on->addDays(3)->toDateString();
    $this->item->update(['scheduled_on' => $newDay]);
    $month = PlanMonth::key($this->plan->month->toImmutable());

    // Before the move: the offer and the item page name the approved post (not the draft).
    $this->actingAs($member)->get(route('socialhq.plan.show', $month))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('items.0.posts_off_day', 2)
            ->where('items.0.posts_back_to_approval', [$approved->scheduled_at->toIso8601String()]));
    $this->actingAs($member)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('itemPosts.0.id', $approved->id)
            ->where('itemPosts.0.back_to_approval', true)
            ->where('itemPosts.1.back_to_approval', false));

    Queue::fake();

    $this->actingAs($member)->post(route('socialhq.plan.items.post.follow', $this->item->id))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.success', trans_choice('socialhq_plan.posts_follow.moved', 1))
        ->assertSessionHas('flash.warning', trans_choice('socialhq_plan.posts_follow.pending', 1, ['count' => 1, 'day' => followDay($this->item)]))
        ->assertSessionMissing('flash.error');

    $local = fn (Post $post): string => CarbonImmutable::parse($post->fresh()->scheduled_at)->setTimezone('Asia/Kathmandu')->format('Y-m-d H:i');

    expect($approved->fresh())
        ->status->toBe(PostStatus::PendingApproval)
        ->approval_requested_by->toBe($member->id)
        ->approved_by->toBeNull()
        ->and($local($approved))->toBe("{$newDay} 18:30")
        ->and($draft->fresh()->status)->toBe(PostStatus::Draft)
        ->and($local($draft))->toBe("{$newDay} 09:15");
});

test('a post a member\'s move sent back reaches the approvers as any member\'s request does', function () {
    $member = workspaceMember($this->workspace, 'member');
    $approved = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString(), '18:30');
    $this->item->update(['scheduled_on' => $this->item->scheduled_on->addDays(2)->toDateString()]);

    Queue::fake();
    Mail::fake();

    $this->actingAs($member)->post(route('socialhq.plan.items.post.follow', $this->item->id))
        ->assertSessionHasNoErrors();

    // The approval request: the same email job a submission from the composer queues, naming the member.
    $requests = Queue::pushed(SendNotification::class, fn (SendNotification $job): bool => $job->mailable instanceof PostApprovalRequested);

    expect($requests)->toHaveCount(1)
        ->and($requests->first()->user->id)->toBe($this->owner->id)
        ->and($requests->first()->mailable->postId)->toBe($approved->id)
        ->and($requests->first()->mailable->requester->id)->toBe($member->id);

    // It becomes the owner's notice in the bell (run as the worker does, from the queued payload).
    unserialize(serialize($requests->first()))->handle();

    expect(Notice::query()->where('user_id', $this->owner->id)->sole())
        ->kind->toBe(NoticeKind::ApprovalRequested)
        ->post_id->toBe($approved->id);

    // The approval history records the member's request.
    expect(ApprovalEvent::query()->where('post_id', $approved->id)->where('action', 'requested')->sole()->user_id)->toBe($member->id);

    // The owner finds it in Publish's approvals, and the member sees it as their own request.
    $this->actingAs($this->owner)->get(route('app.posts.index', ['tab' => 'approvals']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.approvals', 1)
            ->loadDeferredProps(fn ($reload) => $reload->where('posts.data.0.id', $approved->id)));
    $this->actingAs($member)->get(route('app.posts.index', ['tab' => 'approvals']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('counts.approvals', 1));
});

test('an owner moving the post isn\'t warned, and it stays scheduled', function () {
    $approved = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString(), '18:30');
    $this->item->update(['scheduled_on' => $this->item->scheduled_on->addDays(3)->toDateString()]);

    $this->actingAs($this->owner)->get(route('socialhq.plan.show', PlanMonth::key($this->plan->month->toImmutable())))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('items.0.posts_off_day', 1)
            ->where('items.0.posts_back_to_approval', []));
    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('itemPosts.0.back_to_approval', false));

    Queue::fake();

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.post.follow', $this->item->id))
        ->assertSessionHas('flash.success', trans_choice('socialhq_plan.posts_follow.moved', 1))
        ->assertSessionMissing('flash.warning')
        ->assertSessionMissing('flash.error');

    expect($approved->fresh()->status)->toBe(PostStatus::Scheduled);
    Queue::assertNotPushed(SendNotification::class, fn (SendNotification $job): bool => $job->mailable instanceof PostApprovalRequested);
});

test('a member\'s post about to go out keeps its time: neither moved nor sent for approval', function () {
    $member = workspaceMember($this->workspace, 'member');
    $post = Post::factory()->forAccount($this->account)->scheduled()->create([
        'user_id' => $this->owner->id,
        'scheduled_at' => now()->addSeconds(30),
    ]);
    ItemPosts::link($this->item, collect([$post]), $this->owner);

    $this->actingAs($member)->post(route('socialhq.plan.items.post.follow', $this->item->id))
        ->assertSessionHas('flash.error', trans_choice('socialhq_plan.posts_follow.kept', 1, ['count' => 1]))
        ->assertSessionMissing('flash.success')
        ->assertSessionMissing('flash.warning');

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('"move to drafts" says when a post couldn\'t go back to drafts', function () {
    // A member can't change another member's request for approval (Publish hides it from them),
    // so it stays pending and can still go out; the drop says so instead of looking done.
    $member = workspaceMember($this->workspace, 'member');
    $requester = workspaceMember($this->workspace, 'member');
    $theirs = itemPostAt($this->item, $this->account, $requester, 'pendingApproval', $this->item->scheduled_on->toDateString());
    $theirs->update(['approval_requested_by' => $requester->id]);
    $mine = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString(), '15:00');

    $this->actingAs($member)->post(route('socialhq.plan.items.drop', $this->item->id), ['posts' => ItemPosts::TO_DRAFTS])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.warning', trans_choice('socialhq_plan.posts_choice.kept', 1, ['count' => 1]));

    expect($this->item->fresh()->status)->toBe(ItemStatus::Dropped)
        ->and($theirs->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($mine->fresh()->status)->toBe(PostStatus::Draft);
});

test('"move to drafts" by an owner moves every post and says nothing more', function () {
    $requester = workspaceMember($this->workspace, 'member');
    $theirs = itemPostAt($this->item, $this->account, $requester, 'pendingApproval', $this->item->scheduled_on->toDateString());
    $theirs->update(['approval_requested_by' => $requester->id]);

    $this->actingAs($this->owner)->delete(route('socialhq.plan.items.destroy', $this->item->id), ['posts' => ItemPosts::TO_DRAFTS])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('flash.warning');

    expect($theirs->fresh()->status)->toBe(PostStatus::Draft);
});

/**
 * The item edit form's fields, with the given changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function itemEdit(PlanItem $item, array $changes = []): array
{
    return [
        'scheduled_on' => $item->scheduled_on->toDateString(),
        'social_account_id' => $item->social_account_id,
        'content_type' => $item->content_type?->value,
        'creative' => $item->creative->value,
        'title' => $item->title,
        ...$changes,
    ];
}

test('giving an item another channel while its post is scheduled asks what happens to the post first', function () {
    $this->item->update(['platform' => 'linkedin', 'content_type' => ContentType::LinkedInPost]);
    $post = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString());
    $facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $edit = itemEdit($this->item, ['social_account_id' => $facebook->id, 'content_type' => ContentType::FacebookPost->value]);

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.update', $this->item->id), $edit)
        ->assertSessionHasErrors('posts');

    expect($this->item->fresh()->social_account_id)->toBe($this->account->id)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.update', $this->item->id), [...$edit, 'posts' => ItemPosts::TO_DRAFTS])
        ->assertSessionHasNoErrors();

    expect($this->item->fresh())
        ->social_account_id->toBe($facebook->id)
        ->platform->toBe('facebook')
        ->copy_status->toBe(CopyStatus::Draft)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('an item can keep its post scheduled on the old channel, and its page says the post is on another channel', function () {
    $this->item->update(['platform' => 'linkedin', 'content_type' => ContentType::LinkedInPost]);
    $post = itemPostAt($this->item, $this->account, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString());
    $facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.update', $this->item->id), itemEdit($this->item, [
        'social_account_id' => $facebook->id,
        'content_type' => ContentType::FacebookPost->value,
        'posts' => ItemPosts::KEEP,
    ]))->assertSessionHasNoErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);

    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertInertia(fn ($page) => $page->where('itemPosts.0.id', $post->id)
            ->where('itemPosts.0.other_channel', true)
            ->where('itemPosts.0.other_format', true));
});

test('a new format asks too, and edits that keep the channel and format never ask', function () {
    $facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $this->item->update(['social_account_id' => $facebook->id, 'platform' => 'facebook', 'content_type' => ContentType::FacebookPost]);
    $post = itemPostAt($this->item, $facebook, $this->owner, 'scheduled', $this->item->scheduled_on->toDateString());

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.update', $this->item->id), itemEdit($this->item, ['title' => 'A new title']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->owner)->put(route('socialhq.plan.items.update', $this->item->id), itemEdit($this->item->fresh(), ['content_type' => ContentType::FacebookReel->value, 'creative' => CreativeKind::Video->value]))
        ->assertSessionHasErrors('posts');

    expect($this->item->fresh())
        ->title->toBe('A new title')
        ->content_type->toBe(ContentType::FacebookPost)
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});
