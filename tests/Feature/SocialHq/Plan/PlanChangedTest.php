<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanChanged;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefStage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Event;

/*
 * QA 2026-11-18, item 8: an open plan page kept a "Copy approved" status wrong
 * until reload. A change to the month tells the brand's open plan pages.
 */

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->monthKey = $this->month->format('Y-m');
    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);
});

test('a change to the month tells the brand\'s open plan pages once, after the request', function () {
    PlanItem::factory()->for($this->plan, 'plan')->suggested()->count(3)->create();
    Event::fake([PlanChanged::class]);

    // Accept all writes several rows and the plan in one request: one message.
    $this->actingAs($this->owner)->post(route('socialhq.plan.suggestions.accept', $this->monthKey))->assertSessionHasNoErrors();

    Event::assertDispatchedTimes(PlanChanged::class, 1);
    Event::assertDispatched(PlanChanged::class, fn (PlanChanged $event): bool => $event->month === $this->monthKey
        && $event->broadcastOn()[0]->name === "private-workspace.{$this->workspace->id}"
        && $event->broadcastWith() === ['month' => $this->monthKey]);
});

test('copy and brief changes on an item reach the plan page too', function () {
    $item = PlanItem::factory()->for($this->plan, 'plan')->create();
    $brief = Brief::factory()->for($item, 'item')->create();
    Event::fake([PlanChanged::class]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.versions.store', $item->id), ['caption' => 'Fresh momos'])->assertSessionHasNoErrors();
    Event::assertDispatchedTimes(PlanChanged::class, 1);

    $brief->update(['stage' => BriefStage::InProgress]);
    // Outside a request the deferred message goes when the work ends.
    app(DeferredCallbackCollection::class)->invoke();
    Event::assertDispatchedTimes(PlanChanged::class, 2);
});
