<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanStatus;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->member = workspaceMember($this->workspace, 'approval');
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'pillars' => ['Menu', 'Team']]);

    $this->itemPayload = fn (array $overrides = []): array => [
        'scheduled_on' => $this->month->addDays(4)->toDateString(),
        'social_account_id' => $this->instagram->id,
        'content_type' => ContentType::InstagramReel->value,
        'creative' => 'video',
        'pillar' => 'Menu',
        'title' => 'Behind the momo counter',
        'objective' => 'Show how fresh the food is.',
        'angle' => 'Hands folding momos at 6 am.',
        'event_id' => null,
        'owner_id' => null,
        ...$overrides,
    ];

    $this->planWithItem = function (array $planAttributes = [], array $itemAttributes = []): PlanItem {
        $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString(), ...$planAttributes]);

        return PlanItem::factory()->for($plan, 'plan')->create(['social_account_id' => $this->instagram->id, ...$itemAttributes]);
    };
});

test('a member adds an item, which creates the month\'s plan', function () {
    $event = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'starts_on' => $this->month->addDays(5), 'ends_on' => $this->month->addDays(5)]);

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), ($this->itemPayload)(['event_id' => $event->id, 'owner_id' => $this->owner->id]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $plan = ContentPlan::query()->sole();
    $item = $plan->items()->sole();

    expect($plan->workspace_id)->toBe($this->workspace->id)
        ->and($plan->month->toDateString())->toBe($this->month->toDateString())
        ->and($plan->created_by)->toBe($this->member->id)
        ->and($item->status)->toBe(ItemStatus::Planned)
        ->and($item->source)->toBe(ItemSource::Manual)
        ->and($item->platform)->toBe('instagram')
        ->and($item->content_type)->toBe(ContentType::InstagramReel)
        ->and($item->event_id)->toBe($event->id)
        ->and($item->owner_id)->toBe($this->owner->id);
});

test('an item must fit the month, the brand\'s channels, the channel\'s formats, the month\'s events and the workspace\'s people', function (Closure $overrides, string $field) {
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), ($this->itemPayload)($overrides->call($this)))
        ->assertSessionHasErrors($field);

    expect(PlanItem::query()->count())->toBe(0);
})->with([
    'a day of the next month' => [fn () => ['scheduled_on' => $this->month->addMonthNoOverflow()->toDateString()], 'scheduled_on'],
    'a day of the previous month' => [fn () => ['scheduled_on' => $this->month->subDay()->toDateString()], 'scheduled_on'],
    'another workspace\'s channel' => [fn () => ['social_account_id' => SocialAccount::factory()->instagram()->create()->id], 'social_account_id'],
    'a format of another network' => [fn () => ['content_type' => ContentType::LinkedInPost->value], 'content_type'],
    'a pillar the brand doesn\'t have' => [fn () => ['pillar' => 'Memes'], 'pillar'],
    'another account\'s event' => [fn () => ['event_id' => CalendarEvent::factory()->verified()->create(['starts_on' => $this->month->addDays(3), 'ends_on' => $this->month->addDays(3)])->id], 'event_id'],
    'an event of another month' => [fn () => ['event_id' => CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'starts_on' => $this->month->addMonthNoOverflow()->addDays(3), 'ends_on' => $this->month->addMonthNoOverflow()->addDays(3)])->id], 'event_id'],
    'someone outside the workspace' => [fn () => ['owner_id' => User::factory()->create()->id], 'owner_id'],
    'no title' => [fn () => ['title' => ''], 'title'],
]);

test('an unconfirmed event of the month can still be picked by a person', function () {
    $event = CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'starts_on' => $this->month->addDays(5), 'ends_on' => $this->month->addDays(5)]);

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), ($this->itemPayload)(['event_id' => $event->id]))
        ->assertSessionHasNoErrors();
});

test('the account owner can own an item without being a member', function () {
    $accountOwner = $this->workspace->account->owner;
    $this->workspace->members()->detach($accountOwner->id);

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), ($this->itemPayload)(['owner_id' => $accountOwner->id]))
        ->assertSessionHasNoErrors();
});

test('editing an item keeps it inside its own month', function () {
    $item = ($this->planWithItem)();

    $this->actingAs($this->member)
        ->put(route('socialhq.plan.items.update', $item->id), ($this->itemPayload)(['title' => 'Renamed', 'scheduled_on' => $this->month->addDays(20)->toDateString()]))
        ->assertSessionHasNoErrors();

    expect($item->fresh()->title)->toBe('Renamed')
        ->and($item->fresh()->scheduled_on->toDateString())->toBe($this->month->addDays(20)->toDateString());

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.items.move', $item->id), ['scheduled_on' => $this->month->addMonthNoOverflow()->addDays(1)->toDateString()])
        ->assertSessionHasErrors('scheduled_on');

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.items.move', $item->id), ['scheduled_on' => $this->month->addDays(2)->toDateString()])
        ->assertSessionHasNoErrors();

    expect($item->fresh()->scheduled_on->toDateString())->toBe($this->month->addDays(2)->toDateString());
});

test('a renamed pillar doesn\'t block editing an item that still has the old one', function () {
    $item = ($this->planWithItem)([], ['pillar' => 'Old pillar']);

    $this->actingAs($this->member)
        ->put(route('socialhq.plan.items.update', $item->id), ($this->itemPayload)(['pillar' => 'Old pillar']))
        ->assertSessionHasNoErrors();
});

test('suggestions are accepted or dropped, dropped items restored and items deleted', function () {
    $suggestion = ($this->planWithItem)([], ['status' => ItemStatus::Suggested, 'source' => ItemSource::Ai]);
    $other = PlanItem::factory()->for($suggestion->plan, 'plan')->suggested()->create();

    $this->actingAs($this->member)->post(route('socialhq.plan.items.accept', $suggestion->id))->assertSessionHasNoErrors();
    expect($suggestion->fresh()->status)->toBe(ItemStatus::Planned);

    $this->actingAs($this->member)->post(route('socialhq.plan.items.accept', $suggestion->id))->assertSessionHasErrors('item');

    $this->actingAs($this->member)->post(route('socialhq.plan.items.drop', $other->id))->assertSessionHasNoErrors();
    expect($other->fresh()->status)->toBe(ItemStatus::Dropped);

    $this->actingAs($this->member)->post(route('socialhq.plan.items.restore', $other->id))->assertSessionHasNoErrors();
    expect($other->fresh()->status)->toBe(ItemStatus::Planned);

    $this->actingAs($this->member)->delete(route('socialhq.plan.items.destroy', $other->id))->assertRedirect();
    expect($other->fresh())->toBeNull();
});

test('accepting every suggestion leaves planned and dropped items alone', function () {
    $planned = ($this->planWithItem)();
    $plan = $planned->plan;
    $suggestions = PlanItem::factory()->for($plan, 'plan')->suggested()->count(2)->create();
    $dropped = PlanItem::factory()->for($plan, 'plan')->create(['status' => ItemStatus::Dropped]);

    $this->actingAs($this->member)->post(route('socialhq.plan.suggestions.accept', $this->month->format('Y-m')))->assertRedirect();

    expect($suggestions->map(fn (PlanItem $item) => $item->fresh()->status)->all())->toBe([ItemStatus::Planned, ItemStatus::Planned])
        ->and($dropped->fresh()->status)->toBe(ItemStatus::Dropped)
        ->and($planned->fresh()->status)->toBe(ItemStatus::Planned);
});

test('any change to an item of an approved plan sends the plan back for review', function (string $action) {
    // Day 3, so 'move' (to day 10) always changes the date; the factory's random day can be day 10.
    $item = ($this->planWithItem)(
        ['status' => PlanStatus::Approved, 'approved_at' => now(), 'approved_by' => $this->owner->id],
        ['scheduled_on' => $this->month->addDays(2)->toDateString()],
    );

    $request = match ($action) {
        'update' => fn () => $this->put(route('socialhq.plan.items.update', $item->id), ($this->itemPayload)(['title' => 'Changed'])),
        'move' => fn () => $this->post(route('socialhq.plan.items.move', $item->id), ['scheduled_on' => $this->month->addDays(9)->toDateString()]),
        'drop' => fn () => $this->post(route('socialhq.plan.items.drop', $item->id)),
        'destroy' => fn () => $this->delete(route('socialhq.plan.items.destroy', $item->id)),
        'store' => fn () => $this->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), ($this->itemPayload)()),
    };

    $this->actingAs($this->member);
    $request()->assertSessionHasNoErrors();

    expect($item->plan->fresh()->status)->toBe(PlanStatus::InReview)
        ->and($item->plan->fresh()->approved_at)->toBeNull();
})->with(['update', 'move', 'drop', 'destroy', 'store']);

test('another workspace\'s item is not found and an outsider is refused', function () {
    $foreignPlan = ContentPlan::factory()->create();
    $foreign = PlanItem::factory()->for($foreignPlan, 'plan')->create();

    $this->actingAs($this->member)->put(route('socialhq.plan.items.update', $foreign->id), ($this->itemPayload)())->assertNotFound();
    $this->actingAs($this->member)->post(route('socialhq.plan.items.move', $foreign->id), ['scheduled_on' => $this->month->toDateString()])->assertNotFound();
    $this->actingAs($this->member)->post(route('socialhq.plan.items.accept', $foreign->id))->assertNotFound();
    $this->actingAs($this->member)->post(route('socialhq.plan.items.drop', $foreign->id))->assertNotFound();
    $this->actingAs($this->member)->delete(route('socialhq.plan.items.destroy', $foreign->id))->assertNotFound();
    expect($foreign->fresh())->not->toBeNull();

    $item = ($this->planWithItem)();
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), ($this->itemPayload)())->assertForbidden();
    $this->actingAs($outsider)->put(route('socialhq.plan.items.update', $item->id), ($this->itemPayload)())->assertForbidden();
    $this->actingAs($outsider)->delete(route('socialhq.plan.items.destroy', $item->id))->assertForbidden();
    $this->actingAs($outsider)->post(route('socialhq.plan.suggestions.accept', $this->month->format('Y-m')))->assertForbidden();
    expect($item->fresh())->not->toBeNull();
});

test('an item whose event was deleted or whose owner left can still be edited', function () {
    $event = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'starts_on' => $this->month->addDays(4), 'ends_on' => $this->month->addDays(4)]);
    $leaver = workspaceMember($this->workspace, 'member');
    $item = PlanItem::factory()->create([
        'plan_id' => ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()])->id,
        'social_account_id' => $this->instagram->id,
        'event_id' => $event->id,
        'owner_id' => $leaver->id,
    ]);
    $event->delete();
    $this->workspace->members()->detach($leaver->id);

    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.items.update', $item->id), ($this->itemPayload)(['title' => 'Still editable', 'event_id' => $event->id, 'owner_id' => $leaver->id]))
        ->assertSessionHasNoErrors();

    expect($item->fresh()->title)->toBe('Still editable');
});
