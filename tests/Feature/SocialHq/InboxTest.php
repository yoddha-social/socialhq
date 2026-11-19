<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Inbox\WaitingForYou;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanStatus;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefStage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * QA 2026-11-18 P1-1: what waits for someone shows in one place, with a
 * count in the sidebar and a mark on its month.
 */

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->writer = workspaceMember($this->workspace, 'approval');
    $this->designer = workspaceMember($this->workspace, 'approval');

    // Two months ahead and the one after: never this month, whatever day the suite runs.
    $this->month = now('UTC')->addMonthsNoOverflow(2)->startOfMonth();
    $this->nextMonth = $this->month->addMonthNoOverflow();

    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString(), 'status' => PlanStatus::InReview, 'submitted_by' => $this->writer->id, 'submitted_at' => now()]);
    $this->copyItem = PlanItem::factory()->create(['plan_id' => $this->plan->id, 'scheduled_on' => $this->month->addDays(3)->toDateString(), 'copy_status' => CopyStatus::InReview]);
    $this->changedItem = PlanItem::factory()->create(['plan_id' => $this->plan->id, 'scheduled_on' => $this->month->addDays(10)->toDateString(), 'copy_status' => CopyStatus::ChangesRequested]);
    ItemVersion::factory()->for($this->changedItem, 'item')->create(['created_by' => $this->writer->id]);

    $later = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->nextMonth->toDateString()]);
    $briefItem = PlanItem::factory()->create(['plan_id' => $later->id, 'scheduled_on' => $this->nextMonth->addDays(13)->toDateString()]);
    $this->creative = Brief::factory()->for($briefItem, 'item')->create(['stage' => BriefStage::InReview, 'assignee_id' => $this->designer->id]);
    $sentBack = PlanItem::factory()->create(['plan_id' => $later->id, 'scheduled_on' => $this->nextMonth->addDays(19)->toDateString()]);
    $this->sentBack = Brief::factory()->for($sentBack, 'item')->create(['stage' => BriefStage::ChangesRequested, 'assignee_id' => $this->designer->id]);

    $this->post = Post::factory()->pendingApproval()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->writer->id]);
});

test('an approver sees every review: plans, copy, creatives and posts', function () {
    $this->actingAs($this->owner)->get(route('socialhq.inbox'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('socialhq/Inbox')
            ->where('sections.plans.0.month', $this->month->format('Y-m'))
            ->where('sections.plans.0.by', $this->writer->name)
            ->where('sections.copy.0.id', $this->copyItem->id)
            ->has('sections.copy', 1)
            ->where('sections.creatives.0.item_id', $this->creative->item_id)
            ->has('sections.creatives', 1)
            ->where('sections.posts.0.id', $this->post->id)
            ->has('sections.copy_changes', 0)
            ->has('sections.creative_changes', 0)
            ->where('socialhq.inbox.count', 4));
});

test('a member sees only what was sent back to them', function () {
    $this->actingAs($this->writer)->get(route('socialhq.inbox'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sections.plans', 0)
            ->has('sections.copy', 0)
            ->has('sections.posts', 0)
            ->where('sections.copy_changes.0.id', $this->changedItem->id)
            ->has('sections.creative_changes', 0)
            ->where('socialhq.inbox.count', 1));

    $this->actingAs($this->designer)->get(route('socialhq.inbox'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sections.copy_changes', 0)
            ->where('sections.creative_changes.0.id', $this->sentBack->id)
            ->where('socialhq.inbox.count', 1));
});

test('copy sent back counts for the item\'s owner too, and dropped items never count', function () {
    $other = workspaceMember($this->workspace, 'approval');
    $this->changedItem->update(['owner_id' => $other->id]);

    expect(WaitingForYou::count($other, $this->workspace))->toBe(1);

    $this->changedItem->update(['status' => ItemStatus::Dropped]);
    $this->copyItem->update(['status' => ItemStatus::Dropped]);

    expect(WaitingForYou::count($other, $this->workspace))->toBe(0)
        ->and(WaitingForYou::count($this->owner, $this->workspace))->toBe(3);
});

test('the month picker marks the months with something waiting', function () {
    $month = $this->month->format('Y-m');
    $next = $this->nextMonth->format('Y-m');

    $this->actingAs($this->owner)->get(route('socialhq.plan.show', $month))
        ->assertInertia(fn (Assert $page) => $page->where('waitingMonths', [$month, $next]));

    $this->actingAs($this->designer)->get(route('socialhq.plan.show', $month))
        ->assertInertia(fn (Assert $page) => $page->where('waitingMonths', [$next]));
});

test('a creative sent back with nobody assigned waits for the item\'s owner', function () {
    $this->sentBack->update(['assignee_id' => null]);
    $this->sentBack->item->update(['owner_id' => $this->writer->id]);

    expect(WaitingForYou::count($this->designer, $this->workspace))->toBe(0)
        ->and(WaitingForYou::count($this->writer, $this->workspace))->toBe(2);
});

test('nothing from another workspace shows', function () {
    $stranger = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $stranger->account_id, 'user_id' => $stranger->id]);
    $workspace->members()->attach($stranger->id, membershipPivot('admin'));
    $stranger->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($stranger->fresh())->get(route('socialhq.inbox'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sections.plans', 0)
            ->has('sections.copy', 0)
            ->has('sections.creatives', 0)
            ->has('sections.posts', 0)
            ->where('socialhq.inbox.count', 0));
});
