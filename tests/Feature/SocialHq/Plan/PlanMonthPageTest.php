<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->owner = User::factory()->create(['timezone' => 'Asia/Kathmandu']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
});

test('the month page lists the plan, its items, the month\'s events and what items can be made of', function () {
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString(), 'goals' => 'Push the winter menu.']);
    $item = PlanItem::factory()->for($plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => $this->month->addDays(3)->toDateString()]);
    $suggestion = PlanItem::factory()->for($plan, 'plan')->suggested()->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => $this->month->addDays(10)->toDateString()]);
    $confirmed = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'starts_on' => $this->month->addDays(9), 'ends_on' => $this->month->addDays(9)]);
    $unconfirmed = CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'starts_on' => $this->month->addDays(4), 'ends_on' => $this->month->addDays(4)]);
    CalendarEvent::factory()->verified()->create(['starts_on' => $this->month->addDays(5), 'ends_on' => $this->month->addDays(5)]);
    CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'starts_on' => $this->month->addMonthNoOverflow()->addDays(2), 'ends_on' => $this->month->addMonthNoOverflow()->addDays(2)]);
    SocialAccount::factory()->x()->disconnected()->create(['workspace_id' => $this->workspace->id]);
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'pillars' => ['Menu', 'Team'], 'cadence' => [$this->instagram->id => 3]]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->month->format('Y-m')))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('socialhq/plan/Month')
            ->where('month', $this->month->format('Y-m'))
            ->where('plan.id', $plan->id)
            ->where('plan.goals', 'Push the winter menu.')
            ->where('plan.status', 'draft')
            ->has('items', 2)
            ->where('items.0.id', $item->id)
            ->where('items.0.content_type_label', ContentType::InstagramFeed->label())
            ->where('items.1.id', $suggestion->id)
            ->where('items.1.status', 'suggested')
            ->has('events', 2)
            ->where('events.0.id', $unconfirmed->id)
            ->where('events.0.confirmed', false)
            ->where('events.1.id', $confirmed->id)
            ->where('events.1.confirmed', true)
            ->has('planChannels', 1)
            ->where('planChannels.0.id', $this->instagram->id)
            ->where('planChannels.0.content_types.0.value', ContentType::InstagramFeed->value)
            ->where('owners.0.id', $this->owner->id)
            ->where('pillars', ['Menu', 'Team'])
            ->where("cadence.{$this->instagram->id}", 3)
            ->where('abilities.canWork', true)
            ->where('abilities.canApprove', true)
            ->where('abilities.canManageBrand', true));
});

test('without a month the page opens the user\'s current month and creates no plan', function () {
    $current = CarbonImmutable::now('Asia/Kathmandu');

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('month', $current->format('Y-m'))
            ->where('today', $current->toDateString())
            ->where('plan', null)
            ->where('items', []));

    expect(ContentPlan::query()->count())->toBe(0);
});

test('a member works on the plan but cannot approve or change brand settings', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)
        ->get(route('socialhq.plan.show', $this->month->format('Y-m')))
        ->assertInertia(fn ($page) => $page->where('abilities.canWork', true)
            ->where('abilities.canApprove', false)
            ->where('abilities.canManageBrand', false));
});

test('someone outside the workspace cannot open the plan', function () {
    $this->actingAs(workspaceOutsider($this->workspace))
        ->get(route('socialhq.plan.show', $this->month->format('Y-m')))
        ->assertForbidden();
});

test('a brand never sees another brand\'s plan', function () {
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $plan = ContentPlan::factory()->create(['workspace_id' => $other->id, 'month' => $this->month->toDateString()]);
    PlanItem::factory()->for($plan, 'plan')->create();

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->month->format('Y-m')))
        ->assertInertia(fn ($page) => $page->where('plan', null)->where('items', []));
});
