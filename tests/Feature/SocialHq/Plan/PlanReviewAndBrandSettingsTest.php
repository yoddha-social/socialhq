<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\ContentPlan;
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
    $this->member = workspaceMember($this->workspace, 'approval');
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->monthKey = $this->month->format('Y-m');
    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);
});

test('a member sends the plan for review and an approver approves it', function () {
    PlanItem::factory()->for($this->plan, 'plan')->create();

    $this->actingAs($this->member)->post(route('socialhq.plan.submit', $this->monthKey))->assertSessionHasNoErrors();
    expect($this->plan->fresh()->status)->toBe(PlanStatus::InReview)
        ->and($this->plan->fresh()->submitted_by)->toBe($this->member->id);

    $this->actingAs($this->member)->post(route('socialhq.plan.approve', $this->monthKey))->assertForbidden();
    expect($this->plan->fresh()->status)->toBe(PlanStatus::InReview);

    $this->actingAs($this->owner)->post(route('socialhq.plan.approve', $this->monthKey))->assertSessionHasNoErrors();
    expect($this->plan->fresh()->status)->toBe(PlanStatus::Approved)
        ->and($this->plan->fresh()->approved_by)->toBe($this->owner->id)
        ->and($this->plan->fresh()->approved_at)->not->toBeNull();
});

test('a plan with open AI suggestions or nothing planned cannot be approved', function () {
    $this->actingAs($this->owner)->post(route('socialhq.plan.approve', $this->monthKey))->assertSessionHasErrors('plan');
    $this->actingAs($this->member)->post(route('socialhq.plan.submit', $this->monthKey))->assertSessionHasErrors('plan');

    PlanItem::factory()->for($this->plan, 'plan')->create();
    $suggestion = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create();

    $this->actingAs($this->owner)->post(route('socialhq.plan.approve', $this->monthKey))->assertSessionHasErrors('plan');
    expect($this->plan->fresh()->status)->toBe(PlanStatus::Draft);

    $suggestion->update(['status' => ItemStatus::Dropped]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.approve', $this->monthKey))->assertSessionHasNoErrors();
    expect($this->plan->fresh()->status)->toBe(PlanStatus::Approved);
});

test('the team\'s goals are saved on the month, creating its plan when needed', function () {
    $next = $this->month->addMonthNoOverflow();

    $this->actingAs($this->member)
        ->put(route('socialhq.plan.goals.update', $next->format('Y-m')), ['goals' => 'Launch the new menu.'])
        ->assertSessionHasNoErrors();

    expect(ContentPlan::query()->where('workspace_id', $this->workspace->id)->whereDate('month', $next->toDateString())->value('goals'))->toBe('Launch the new menu.');

    $this->actingAs(workspaceOutsider($this->workspace))
        ->put(route('socialhq.plan.goals.update', $this->monthKey), ['goals' => 'x'])
        ->assertForbidden();
});

test('an admin saves pillars and posts per week per channel on the brand profile', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.brand-settings.update'), ['pillars' => [' Menu ', 'Team'], 'cadence' => [$instagram->id => 4]])
        ->assertSessionHasNoErrors();

    $profile = BrandProfile::query()->where('workspace_id', $this->workspace->id)->sole();

    expect($profile->pillars)->toBe(['Menu', 'Team'])
        ->and($profile->cadence)->toEqual([$instagram->id => 4]);
});

test('brand settings refuse too many, long or repeated pillars, another brand\'s channel and a cadence out of range', function (Closure $payload, string $field) {
    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.brand-settings.update'), $payload->call($this))
        ->assertSessionHasErrors($field);

    expect(BrandProfile::query()->count())->toBe(0);
})->with([
    'nine pillars' => [fn () => ['pillars' => array_map(fn (int $n): string => "Pillar {$n}", range(1, 9)), 'cadence' => []], 'pillars'],
    'a long pillar' => [fn () => ['pillars' => [str_repeat('a', 41)], 'cadence' => []], 'pillars.0'],
    'the same pillar twice' => [fn () => ['pillars' => ['Menu', 'menu'], 'cadence' => []], 'pillars.0'],
    'another brand\'s channel' => [fn () => ['pillars' => [], 'cadence' => [SocialAccount::factory()->create()->id => 2]], 'cadence'],
    'fifteen posts a week' => [fn () => ['pillars' => [], 'cadence' => [SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'id' => '0199a000-0000-7000-8000-000000000001'])->id => 15]], 'cadence.0199a000-0000-7000-8000-000000000001'],
]);

test('only admins change brand settings', function () {
    $this->actingAs($this->member)
        ->put(route('socialhq.plan.brand-settings.update'), ['pillars' => ['Menu'], 'cadence' => []])
        ->assertForbidden();
});
