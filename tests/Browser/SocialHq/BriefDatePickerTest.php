<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;

/*
 * QA 2026-11-18, item 7: after a pick the brief's date picker stayed open and
 * swallowed the next click, and "Save brief" needed 2–3 clicks. Picking the
 * selected day again cleared it and left the picker stuck, so later picks
 * never reached the form.
 */

test('the due date picker closes on every pick, keeps a re-picked day and saves the last one', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $plan = ContentPlan::factory()->create(['workspace_id' => $workspace->id]);
    $item = PlanItem::factory()->create(['plan_id' => $plan->id]);
    $brief = Brief::factory()->for($item, 'item')->create(['due_on' => null]);

    // Days in the middle of the month the picker opens on (today's), never outside it.
    $first = now('UTC')->startOfMonth()->addDays(9)->toDateString();
    $second = now('UTC')->startOfMonth()->addDays(14)->toDateString();
    $day = fn (string $date): string => "[data-testid=\"socialhq-brief-due-calendar\"] [data-value=\"{$date}\"]:not([data-outside-view])";

    $this->actingAs($user->fresh());
    $page = visit(route('socialhq.plan.items.show', $item->id))->resize(1440, 900);
    waitFor($page, '@socialhq-brief-due-trigger');
    waitForState($page, '@item-brief-save', 'element.disabled');

    $page->click('@socialhq-brief-due-trigger');
    waitFor($page, $day($first));
    $page->click($day($first));
    waitForGone($page, '@socialhq-brief-due-calendar');
    waitForState($page, '@item-brief-save', '!element.disabled');

    // The selected day again: it stays picked and the picker still closes.
    $page->click('@socialhq-brief-due-trigger');
    waitFor($page, $day($first));
    $page->click($day($first));
    waitForGone($page, '@socialhq-brief-due-calendar');

    // A later pick still reaches the form, and one click saves it.
    $page->click('@socialhq-brief-due-trigger');
    waitForState($page, $day($first), "element.hasAttribute('data-selected')");
    $page->click($day($second));
    waitForGone($page, '@socialhq-brief-due-calendar');
    $page->click('@item-brief-save');
    waitForState($page, '@item-brief-save', 'element.disabled');

    expect($brief->fresh()->due_on?->toDateString())->toBe($second);
    $page->assertNoJavaScriptErrors();
});
