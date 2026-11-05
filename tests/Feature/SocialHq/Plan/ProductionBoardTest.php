<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefKind;
use App\SocialHq\Production\BriefStage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Asha Owner']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->designer = workspaceMember($this->workspace, 'approval');
});

function boardBrief(ContentPlan $plan, array $attributes = [], array $itemAttributes = []): Brief
{
    $item = PlanItem::factory()->create(['plan_id' => $plan->id, ...$itemAttributes]);

    return Brief::factory()->for($item, 'item')->create($attributes);
}

test('the board lists the workspace\'s briefs with their cards, and filters them', function () {
    $mine = boardBrief($this->plan, ['assignee_id' => $this->designer->id, 'kind' => BriefKind::Video, 'stage' => BriefStage::InProgress]);
    $other = boardBrief($this->plan, ['kind' => BriefKind::Graphic]);
    Brief::factory()->create();

    $this->actingAs($this->designer)->get(route('socialhq.plan.production'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('socialhq/plan/Production')
            ->has('cards', 2)
            ->where('hasBriefs', true)
            ->where('stages', ['todo', 'in_progress', 'in_review', 'changes_requested', 'approved'])
            ->where('approverNames', ['Asha Owner'])
            ->where('months', [$this->plan->month->format('Y-m')]));

    $this->actingAs($this->designer)->get(route('socialhq.plan.production', ['mine' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('cards', 1)
            ->where('cards.0.id', $mine->id)
            ->where('cards.0.assignee.id', $this->designer->id)
            ->where('cards.0.stage', 'in_progress')
            ->where('cards.0.title', $mine->item->title));

    $this->actingAs($this->designer)->get(route('socialhq.plan.production', ['kind' => 'graphic']))
        ->assertInertia(fn (Assert $page) => $page->has('cards', 1)->where('cards.0.id', $other->id));

    $elsewhere = $this->plan->month->addMonths(3)->format('Y-m');
    $this->actingAs($this->designer)->get(route('socialhq.plan.production', ['month' => $elsewhere]))
        ->assertInertia(fn (Assert $page) => $page->has('cards', 0)->where('hasBriefs', true));
});

test('a card moves between columns and keeps the order it was dropped in', function () {
    $first = boardBrief($this->plan, ['stage' => BriefStage::InProgress, 'position' => 0]);
    $second = boardBrief($this->plan, ['stage' => BriefStage::InProgress, 'position' => 1]);
    $moving = boardBrief($this->plan, ['stage' => BriefStage::Todo]);

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $moving->id), [
        'stage' => 'in_progress',
        'ordered_ids' => [$first->id, $moving->id, $second->id],
    ])->assertOk();

    expect(Brief::query()->where('stage', BriefStage::InProgress)->orderBy('position')->pluck('id')->all())
        ->toBe([$first->id, $moving->id, $second->id]);

    // Reordering within a column.
    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $second->id), [
        'stage' => 'in_progress',
        'ordered_ids' => [$second->id, $first->id, $moving->id],
    ])->assertOk();

    expect(Brief::query()->where('stage', BriefStage::InProgress)->orderBy('position')->pluck('id')->all())
        ->toBe([$second->id, $first->id, $moving->id]);
});

test('only approvers move a card into or out of Approved', function () {
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InReview]);

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'approved', 'ordered_ids' => [$brief->id]])
        ->assertForbidden()
        ->assertJsonPath('message', __('socialhq_production.board.errors.approvers_only', ['names' => 'Asha Owner']));
    expect($brief->fresh()->stage)->toBe(BriefStage::InReview);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'approved', 'ordered_ids' => [$brief->id]])->assertOk();
    $brief->refresh();
    expect($brief->stage)->toBe(BriefStage::Approved)
        ->and($brief->approved_by)->toBe($this->owner->id)
        ->and(ItemReview::query()->where('brief_id', $brief->id)->sole()->kind)->toBe(ReviewKind::Approved);

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_progress', 'ordered_ids' => [$brief->id]])->assertForbidden();
    expect($brief->fresh()->stage)->toBe(BriefStage::Approved);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_progress', 'ordered_ids' => [$brief->id]])->assertOk();
    expect($brief->fresh()->stage)->toBe(BriefStage::InProgress)
        ->and($brief->fresh()->approved_at)->toBeNull();
});

test('cards of another workspace cannot be moved and outsiders see no board', function () {
    $foreign = Brief::factory()->create();

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $foreign->id), ['stage' => 'in_progress', 'ordered_ids' => [$foreign->id]])->assertNotFound();
    $this->actingAs(workspaceOutsider($this->workspace))->get(route('socialhq.plan.production'))->assertForbidden();

    expect($foreign->fresh()->stage)->toBe(BriefStage::Todo);
});
