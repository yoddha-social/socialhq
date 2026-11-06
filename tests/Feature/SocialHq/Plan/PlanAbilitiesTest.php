<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);
    $this->item = PlanItem::factory()->create(['plan_id' => $plan->id, 'scheduled_on' => $this->month->addDays(4)->toDateString()]);
});

dataset('plan pages', [
    'month' => 'socialhq/plan/Month',
    'item' => 'socialhq/plan/Item',
    'production' => 'socialhq/plan/Production',
    'events' => 'socialhq/plan/Events',
]);

/**
 * The URL of a plan page, for the plan and item made in beforeEach.
 */
function planPageUrl(string $component, CarbonImmutable $month, PlanItem $item): string
{
    return match ($component) {
        'socialhq/plan/Month' => route('socialhq.plan.show', $month->format('Y-m')),
        'socialhq/plan/Item' => route('socialhq.plan.items.show', $item->id),
        'socialhq/plan/Production' => route('socialhq.plan.production'),
        'socialhq/plan/Events' => route('socialhq.plan.events.index'),
    };
}

test('a member whose posts need approval works on every plan page but approves and manages nothing', function (string $component) {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)
        ->get(planPageUrl($component, $this->month, $this->item))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('abilities.canWork', true)
            ->where('abilities.canApprove', false)
            ->where('abilities.canManageBrand', false)
            ->where('abilities.canManageSharedEvents', false));
})->with('plan pages');

test('an admin works, approves and manages the brand on every plan page', function (string $component) {
    $this->actingAs($this->owner)
        ->get(planPageUrl($component, $this->month, $this->item))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('abilities.canWork', true)
            ->where('abilities.canApprove', true)
            ->where('abilities.canManageBrand', true)
            ->where('abilities.canManageSharedEvents', true));
})->with('plan pages');

test('the production board names who may move cards into or out of the decided stages', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)
        ->get(route('socialhq.plan.production'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('socialhq/plan/Production')
            ->where('approverNames', [$this->owner->name]));
});

test('an admin of one brand approves its dates but not the dates every brand shares', function () {
    $admin = workspaceMember($this->workspace, 'admin');
    Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);

    $this->actingAs($admin)
        ->get(route('socialhq.plan.events.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('socialhq/plan/Events')
            ->where('abilities.canApprove', true)
            ->where('abilities.canManageSharedEvents', false));
});
