<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\ItemReview;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\ReviewKind;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->item = PlanItem::factory()->create(['plan_id' => $plan->id, 'owner_id' => $this->owner->id]);
});

function copyPayload(string $caption = 'Dashain is here. Fresh momos, delivered.'): array
{
    return ['caption' => $caption, 'hashtags' => '#dashain #momo', 'script' => null, 'notes' => 'Keep it warm.'];
}

test('the item page shows the item, its versions and its thread', function () {
    ItemVersion::factory()->for($this->item, 'item')->create(['caption' => 'First', 'created_by' => $this->owner->id]);
    ItemReview::query()->create(['item_id' => $this->item->id, 'user_id' => $this->owner->id, 'kind' => ReviewKind::Comment, 'body' => 'Looks good']);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.items.show', $this->item->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('socialhq/plan/Item')
            ->where('item.id', $this->item->id)
            ->where('item.title', $this->item->title)
            ->where('item.month', $this->item->plan->month->format('Y-m'))
            ->where('item.max_length', 2200)
            ->where('item.owner.id', $this->owner->id)
            ->has('versions', 1)
            ->where('versions.0.caption', 'First')
            ->where('versions.0.author.id', $this->owner->id)
            ->has('thread', 1)
            ->where('thread.0.body', 'Looks good')
            ->where('abilities.canApprove', true));
});

test('an item of another workspace is not found, and outsiders are refused', function () {
    $user = workspaceMember($this->workspace, 'member');
    $other = PlanItem::factory()->create();

    $this->actingAs($user)->get(route('socialhq.plan.items.show', $other->id))->assertNotFound();
    $this->actingAs($user)->post(route('socialhq.plan.items.versions.store', $other->id), copyPayload())->assertNotFound();
    $this->actingAs($user)->post(route('socialhq.plan.items.comments.store', $other->id), ['body' => 'Hi'])->assertNotFound();

    $outsider = workspaceOutsider($this->workspace);
    $this->actingAs($outsider)->get(route('socialhq.plan.items.show', $this->item->id))->assertForbidden();

    expect(ItemVersion::query()->count())->toBe(0);
});

test('saving copy always adds a numbered version and never edits one', function () {
    $user = workspaceMember($this->workspace, 'approval');

    $this->actingAs($user)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload('One'))->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload('Two'))->assertSessionHasNoErrors();

    $versions = ItemVersion::query()->where('item_id', $this->item->id)->orderBy('number')->get();

    expect($versions->pluck('number')->all())->toBe([1, 2])
        ->and($versions->pluck('caption')->all())->toBe(['One', 'Two'])
        ->and($versions->first()->created_by)->toBe($user->id)
        ->and($versions->first()->source->value)->toBe('manual')
        ->and($this->item->fresh()->copy_status)->toBe(CopyStatus::Draft);

    $this->actingAs($user)->post(route('socialhq.plan.items.versions.store', $this->item->id), ['caption' => ''])
        ->assertSessionHasErrors('caption');
});

test('copy goes through review: submit, changes requested with a note, approve', function () {
    $writer = workspaceMember($this->workspace, 'approval');
    $this->actingAs($writer)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload());

    $this->actingAs($writer)->post(route('socialhq.plan.items.copy.submit', $this->item->id))->assertSessionHasNoErrors();
    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::InReview);

    // A writer who can't approve is refused both decisions.
    $this->actingAs($writer)->post(route('socialhq.plan.items.copy.approve', $this->item->id))->assertForbidden();
    $this->actingAs($writer)->post(route('socialhq.plan.items.copy.request-changes', $this->item->id), ['note' => 'x'])->assertForbidden();

    // A change request needs a note.
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.request-changes', $this->item->id), ['note' => ''])
        ->assertSessionHasErrors('note');
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.request-changes', $this->item->id), ['note' => 'Shorter hook'])
        ->assertSessionHasNoErrors();
    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::ChangesRequested);

    $this->actingAs($writer)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload('Shorter'));
    $this->actingAs($writer)->post(route('socialhq.plan.items.copy.submit', $this->item->id))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.approve', $this->item->id))->assertSessionHasNoErrors();

    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::Approved)
        ->and(ItemReview::query()->where('item_id', $this->item->id)->orderBy('id')->pluck('kind')->map->value->all())
        ->toBe(['submitted', 'changes_requested', 'submitted', 'approved']);

    $approval = ItemReview::query()->where('kind', ReviewKind::Approved)->sole();
    expect($approval->version->number)->toBe(2);
});

test('copy that is not in review cannot be approved, and an empty item cannot be submitted', function () {
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.submit', $this->item->id))->assertSessionHasErrors('copy');

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload());
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.approve', $this->item->id))->assertSessionHasErrors('copy');

    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::Draft);
});

test('a new version of approved copy sends it back to draft and notes it in the thread', function () {
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload());
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.submit', $this->item->id));
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.copy.approve', $this->item->id));

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload('Edited after approval'));

    $reopened = ItemReview::query()->where('kind', ReviewKind::Reopened)->sole();

    expect($this->item->fresh()->copy_status)->toBe(CopyStatus::Draft)
        ->and($reopened->version->number)->toBe(2)
        ->and($reopened->user_id)->toBe($this->owner->id);
});

test('comments are linked to the copy version they are about', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $this->actingAs($member)->post(route('socialhq.plan.items.versions.store', $this->item->id), copyPayload());

    $this->actingAs($member)->post(route('socialhq.plan.items.comments.store', $this->item->id), ['body' => 'Check the price', 'about' => 'copy'])
        ->assertSessionHasNoErrors();
    $this->actingAs($member)->post(route('socialhq.plan.items.comments.store', $this->item->id), ['body' => ''])
        ->assertSessionHasErrors('body');

    $comment = ItemReview::query()->sole();

    expect($comment->kind)->toBe(ReviewKind::Comment)
        ->and($comment->version->number)->toBe(1)
        ->and($comment->user_id)->toBe($member->id);
});
