<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefKind;
use App\SocialHq\Production\BriefRounds;
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

test('a dropped item\'s brief leaves the board until the item is restored', function () {
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InProgress]);
    boardBrief($this->plan);

    $brief->item->update(['status' => ItemStatus::Dropped]);

    $this->actingAs($this->designer)->get(route('socialhq.plan.production'))
        ->assertInertia(fn (Assert $page) => $page->has('cards', 1)->where('cards.0.id', fn (int $id): bool => $id !== $brief->id));

    $brief->item->update(['status' => ItemStatus::Planned]);

    $this->actingAs($this->designer)->get(route('socialhq.plan.production'))
        ->assertInertia(fn (Assert $page) => $page->has('cards', 2));
});

test('the board and the item page name a channel the way the team does, with its network', function () {
    $channel = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id, 'display_name' => 'Acme']);
    $channel->forceFill(['shq_name' => 'Acme Kathmandu'])->save();
    $brief = boardBrief($this->plan, [], ['social_account_id' => $channel->id, 'platform' => 'facebook']);

    $this->actingAs($this->designer)->get(route('socialhq.plan.production'))
        ->assertInertia(fn (Assert $page) => $page->where('cards.0.channel', 'Acme Kathmandu')->where('cards.0.platform', 'facebook'));

    $this->actingAs($this->designer)->get(route('socialhq.plan.items.show', $brief->item_id))
        ->assertInertia(fn (Assert $page) => $page->where('item.channel.name', 'Acme Kathmandu')->where('item.channel.platform', 'facebook'));
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
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 1, 'path' => 'socialhq/briefs/test/one.png', 'original_name' => 'one.png', 'mime_type' => 'image/png', 'size' => 3]);

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

test('only approvers move a card into or out of Changes requested', function () {
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InReview]);
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 1, 'path' => 'a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 1]);

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'changes_requested', 'ordered_ids' => [$brief->id]])->assertForbidden();
    expect($brief->fresh()->stage)->toBe(BriefStage::InReview);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'changes_requested', 'ordered_ids' => [$brief->id]])->assertOk();
    expect(ItemReview::query()->where('brief_id', $brief->id)->sole()->kind)->toBe(ReviewKind::ChangesRequested);

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_progress', 'ordered_ids' => [$brief->id]])->assertForbidden();
    expect($brief->fresh()->stage)->toBe(BriefStage::ChangesRequested);
});

test('a card goes to In review on the board only with a round that may be reviewed', function () {
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InProgress]);

    // No files yet.
    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_review', 'ordered_ids' => [$brief->id]])
        ->assertConflict()
        ->assertJsonPath('message', __('socialhq_production.board.errors.review_needs_files'));

    // A round that already got change requests.
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 1, 'path' => 'a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 1]);
    $review = ItemReview::query()->create(['item_id' => $brief->item_id, 'brief_id' => $brief->id, 'round' => 1, 'user_id' => $this->owner->id, 'kind' => ReviewKind::ChangesRequested, 'body' => 'Again']);
    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_review', 'ordered_ids' => [$brief->id]])->assertConflict();
    expect($brief->fresh()->stage)->toBe(BriefStage::InProgress);

    $review->delete();
    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_review', 'ordered_ids' => [$brief->id]])->assertOk();

    expect($brief->fresh()->stage)->toBe(BriefStage::InReview)
        ->and(ItemReview::query()->where('brief_id', $brief->id)->sole()->kind)->toBe(ReviewKind::Submitted);
});

test('cards of another workspace cannot be moved and outsiders see no board', function () {
    $foreign = Brief::factory()->create();

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $foreign->id), ['stage' => 'in_progress', 'ordered_ids' => [$foreign->id]])->assertNotFound();
    $this->actingAs(workspaceOutsider($this->workspace))->get(route('socialhq.plan.production'))->assertForbidden();

    expect($foreign->fresh()->stage)->toBe(BriefStage::Todo);
});

test('a creative with no files can\'t be dragged into Approved, even by an approver', function () {
    $brief = boardBrief($this->plan, ['stage' => 'in_progress']);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), [
        'stage' => 'approved',
        'ordered_ids' => [$brief->id],
    ])->assertUnprocessable();

    expect($brief->fresh()->stage->value)->toBe('in_progress');
});

test('dragging decides only a round that is in review, like the review buttons', function () {
    // Files uploaded but never sent for review.
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InProgress]);
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 1, 'path' => 'a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 1]);

    foreach (['approved', 'changes_requested'] as $stage) {
        $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => $stage, 'ordered_ids' => [$brief->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('creative');
    }

    // Changes were requested on the round: it can't be approved by dragging it back.
    $brief->update(['stage' => BriefStage::ChangesRequested]);
    ItemReview::query()->create(['item_id' => $brief->item_id, 'brief_id' => $brief->id, 'round' => 1, 'user_id' => $this->owner->id, 'kind' => ReviewKind::ChangesRequested, 'body' => 'Again']);
    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'approved', 'ordered_ids' => [$brief->id]])->assertUnprocessable();

    expect($brief->fresh()->stage)->toBe(BriefStage::ChangesRequested)
        ->and($brief->fresh()->approved_at)->toBeNull();
});

test('a drag decides only the round the approver saw', function () {
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InReview]);
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 2, 'path' => 'a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 1]);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'approved', 'ordered_ids' => [$brief->id], 'round' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('errors.creative.0', __('socialhq_production.review.errors.changed'));
    expect($brief->fresh()->stage)->toBe(BriefStage::InReview);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'approved', 'ordered_ids' => [$brief->id], 'round' => 2])->assertOk();
    expect($brief->fresh()->stage)->toBe(BriefStage::Approved);
});

test('only approvers take a card out of review', function () {
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InReview]);
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 1, 'path' => 'a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 1]);

    $this->actingAs($this->designer)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_progress', 'ordered_ids' => [$brief->id]])->assertForbidden();
    expect($brief->fresh()->stage)->toBe(BriefStage::InReview);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_progress', 'ordered_ids' => [$brief->id]])->assertOk();
    expect($brief->fresh()->stage)->toBe(BriefStage::InProgress);
});

test('a reopened creative starts a new round, so the final files are only the new ones', function () {
    $brief = boardBrief($this->plan, ['stage' => BriefStage::InReview]);
    BriefFile::query()->create(['brief_id' => $brief->id, 'round' => 1, 'path' => 'a.png', 'original_name' => 'a.png', 'mime_type' => 'image/png', 'size' => 1]);

    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'approved', 'ordered_ids' => [$brief->id]])->assertOk();
    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_progress', 'ordered_ids' => [$brief->id]])->assertOk();

    $brief->refresh();
    expect(BriefRounds::uploadRound($brief))->toBe(2)
        ->and(BriefRounds::submitBlocker($brief))->not->toBeNull()
        ->and(ItemReview::query()->where('brief_id', $brief->id)->latest('id')->first()->kind)->toBe(ReviewKind::Reopened);

    // The reopened round can't go back to review or be approved without new files.
    $this->actingAs($this->owner)->putJson(route('socialhq.plan.board.move', $brief->id), ['stage' => 'in_review', 'ordered_ids' => [$brief->id]])->assertConflict();
});

test('without a month the board shows open work and what was approved for this month or later', function () {
    $pastPlan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString()]);
    $oldApproved = boardBrief($pastPlan, ['stage' => BriefStage::Approved]);
    $oldOpen = boardBrief($pastPlan, ['stage' => BriefStage::InProgress]);
    $currentPlan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => now()->startOfMonth()->toDateString()]);
    $currentApproved = boardBrief($currentPlan, ['stage' => BriefStage::Approved]);

    $this->actingAs($this->designer)->get(route('socialhq.plan.production'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards', fn ($cards) => collect($cards)->pluck('id')->sort()->values()->all() === collect([$oldOpen->id, $currentApproved->id])->sort()->values()->all())
            ->where('months', [now()->startOfMonth()->format('Y-m'), now()->subMonthsNoOverflow(2)->format('Y-m')]));

    $this->actingAs($this->designer)->get(route('socialhq.plan.production', ['month' => now()->subMonthsNoOverflow(2)->format('Y-m')]))
        ->assertInertia(fn (Assert $page) => $page->has('cards', 2));

    expect($oldApproved->exists)->toBeTrue();
});
