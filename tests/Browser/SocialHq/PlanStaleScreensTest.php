<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanMonth;
use Carbon\CarbonImmutable;

/*
 * QA 2026-11-18, items 8 and 9: clicks on the month while an item was open
 * did nothing, "Next month" clicked twice went one month, and what was typed
 * on an item page was lost without a word.
 */

/**
 * @return array{0: User, 1: ContentPlan, 2: PlanItem, 3: PlanItem}
 */
function staleScreensPlan(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    // The middle of next month: never in this month's grid, never in the past.
    $month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $plan = ContentPlan::factory()->create(['workspace_id' => $workspace->id, 'month' => $month->toDateString()]);
    $item = fn (string $title, int $day): PlanItem => PlanItem::factory()->create([
        'plan_id' => $plan->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform->value,
        'content_type' => ContentType::LinkedInPost,
        'creative' => CreativeKind::None,
        'title' => $title,
        'scheduled_on' => $month->addDays($day)->toDateString(),
    ]);

    return [$user->fresh(), $plan, $item('Dashain greetings', 9), $item('Tihar lights', 14)];
}

function visitStalePlan(ContentPlan $plan): mixed
{
    $page = visit(route('socialhq.plan.show', PlanMonth::key($plan->month->toImmutable())))->resize(1440, 900);
    waitFor($page, '@plan-add-item');

    return $page;
}

test('with an item open, another card opens that item and Add item opens a new one', function () {
    [$user, $plan, $first, $second] = staleScreensPlan();
    $this->actingAs($user);

    $page = visitStalePlan($plan);
    $page->click("@plan-item-open-{$first->id}");
    waitForState($page, '@plan-item-title', "element.value === 'Dashain greetings'");

    $page->click("@plan-item-open-{$second->id}");
    waitForState($page, '@plan-item-title', "element.value === 'Tihar lights'");

    $page->click('@plan-add-item');
    waitForState($page, '@plan-item-title', "element.value === ''");
    $page->assertVisible('@plan-item-sheet')->assertNoJavaScriptErrors();
});

test('unsaved changes to an open item wait until they are discarded', function () {
    [$user, $plan, $first, $second] = staleScreensPlan();
    $this->actingAs($user);

    $page = visitStalePlan($plan);
    $page->click("@plan-item-open-{$first->id}");
    waitForState($page, '@plan-item-title', "element.value === 'Dashain greetings'");
    $page->fill('@plan-item-title', 'Dashain greetings, family');

    $page->click("@plan-item-open-{$second->id}");
    waitForText($page, '@plan-sheet-unsaved-toast', __('socialhq_plan.sheet.unsaved_switch'));
    $page->assertValue('@plan-item-title', 'Dashain greetings, family');

    $page->click('[data-testid="plan-sheet-unsaved-toast"] [data-button]');
    waitForState($page, '@plan-item-title', "element.value === 'Tihar lights'");
    expect($first->fresh()->title)->toBe('Dashain greetings');
    $page->assertNoJavaScriptErrors();
});

test('two quick clicks on next month go two months ahead', function () {
    [$user, $plan] = staleScreensPlan();
    $this->actingAs($user);

    $page = visitStalePlan($plan);
    $page->script("(() => { const next = document.querySelector('[data-testid=\"plan-month-next\"]'); next.click(); next.click(); return true; })()");

    waitForPath($page, route('socialhq.plan.show', PlanMonth::key($plan->month->toImmutable()->addMonthsNoOverflow(2)), absolute: false));
    $page->assertNoJavaScriptErrors();
});

test('leaving an item page with a comment typed asks first', function () {
    [$user, $plan, $first] = staleScreensPlan();
    $this->actingAs($user);

    $page = visit(route('socialhq.plan.items.show', $first->id))->resize(1440, 900);
    waitFor($page, '@item-review-comment');
    $page->fill('@item-review-comment', 'Can we use the festival red?');

    $page->click('@item-back');
    waitFor($page, '@item-unsaved-dialog');
    $page->click('@item-unsaved-keep');
    waitForGone($page, '@item-unsaved-dialog');
    $page->assertValue('@item-review-comment', 'Can we use the festival red?')
        ->assertPathIs(route('socialhq.plan.items.show', $first->id, absolute: false));

    $page->click('@item-back');
    waitFor($page, '@item-unsaved-dialog');
    $page->click('@item-unsaved-discard');
    waitForPath($page, route('socialhq.plan.show', PlanMonth::key($plan->month->toImmutable()), absolute: false));
    $page->assertNoJavaScriptErrors();
});

test('back to the month shows what changed meanwhile', function () {
    [$user, $plan, $first] = staleScreensPlan();
    $this->actingAs($user);

    $page = visitStalePlan($plan);
    $page->click("@plan-item-open-{$first->id}");
    waitFor($page, '@plan-item-open-page');
    $page->click('@plan-item-open-page');
    waitFor($page, '@item-title');

    $first->update(['title' => 'Dashain greetings, renamed']);
    $page->back();

    waitForText($page, "@plan-item-open-{$first->id}", 'Dashain greetings, renamed');
    $page->assertNoJavaScriptErrors();
});
