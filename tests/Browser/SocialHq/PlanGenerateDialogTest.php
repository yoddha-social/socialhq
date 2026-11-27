<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\User\WeekStart;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanMonth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
 * Owner feedback 2026-11-26: the Generate dialog's two day dropdowns were
 * hard to use ("use proper calendar"). The days are one field with a month
 * calendar and quick choices, and what the AI reads is a row of option cards
 * instead of switches. What the dialog sends is unchanged.
 */

test('generate picks its days on a calendar and its sources on option cards', function () {
    Queue::fake();
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.structured' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);

    $user = User::factory()->weekStartsOn(WeekStart::Monday)->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    // A coming month whose 1st isn't a Monday, so its first row starts with days of the month before.
    $month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();

    while ($month->isMonday()) {
        $month = $month->addMonthNoOverflow();
    }

    $before = $month->subDay();
    $first = $month->addDays(9);
    $last = $month->addDays(13);
    expect($before->month)->not->toBe($month->month)
        ->and($last->month)->toBe($month->month);

    $plan = ContentPlan::factory()->create(['workspace_id' => $workspace->id, 'month' => $month->toDateString()]);
    // Plain CSS, not "@id …": Pest's own click() reads "@" only as a whole selector.
    $day = fn (CarbonImmutable $date): string => "[data-testid=\"plan-generate-range-calendar\"] [data-value=\"{$date->toDateString()}\"]";
    $days = fn (CarbonImmutable $from, CarbonImmutable $to): string => "{$from->format('D j')} – {$to->format('D j M')}";
    $pressed = fn (string $state): string => "element.getAttribute('aria-pressed') === '{$state}'";

    $this->actingAs($user->fresh());
    $page = visit(route('socialhq.plan.show', PlanMonth::key($month)))->resize(1440, 900);
    waitFor($page, '@plan-generate');
    $page->click('@plan-generate');
    waitFor($page, '@plan-generate-dialog');

    // A coming month opens on its first week.
    waitForText($page, '@plan-generate-range-days', $days($month, $month->addDays(6)));

    // "Whole month" picks every day in one click and closes the calendar.
    $page->click('@plan-generate-range');
    waitFor($page, '@plan-generate-range-whole-month');
    $page->click('@plan-generate-range-whole-month');
    waitForGone($page, '@plan-generate-range-calendar');
    waitForText($page, '@plan-generate-range-days', $days($month, $month->endOfMonth()));

    // The last day of the month before is shown greyed out, and clicking it picks nothing.
    $page->click('@plan-generate-range');
    waitForState($page, $day($before), "element.hasAttribute('data-disabled')");
    $page->script('(() => { document.querySelector('.json_encode($day($before)).').click(); return true; })()');
    waitForState($page, $day($month), "element.hasAttribute('data-selection-start')");
    expect($page->script('document.querySelector('.json_encode($day($before)).').hasAttribute("data-selected")'))->toBeFalse();
    waitForText($page, '@plan-generate-range-status', __('socialhq_plan.generate.range_pick'));

    // Two clicks pick the first and the last day.
    $page->click($day($first));
    waitForText($page, '@plan-generate-range-status', __('socialhq_plan.generate.range_pick_last'));
    $page->click($day($last));
    waitForGone($page, '@plan-generate-range-calendar');
    waitForText($page, '@plan-generate-range-days', $days($first, $last));

    // What the AI reads: cards that switch off and on.
    waitForState($page, '@plan-generate-old_calendars', $pressed('true'));
    waitForState($page, '@plan-generate-past_posts', $pressed('true'));
    $page->click('@plan-generate-past_posts');
    waitForState($page, '@plan-generate-past_posts', $pressed('false'));
    $page->click('@plan-generate-old_calendars');
    waitForState($page, '@plan-generate-old_calendars', $pressed('false'));
    $page->click('@plan-generate-old_calendars');
    waitForState($page, '@plan-generate-old_calendars', $pressed('true'));

    $page->click('@plan-generate-start');
    waitForGone($page, '@plan-generate-dialog');
    waitForDatabase($page, fn (): bool => $plan->fresh()->generation_options !== null);

    expect($plan->fresh()->generation_options)->toMatchArray([
        'from' => $first->toDateString(),
        'to' => $last->toDateString(),
        'old_calendars' => true,
        'past_posts' => false,
    ]);
    $page->assertNoJavaScriptErrors();
});

/*
 * Owner feedback 2026-11-26: "nothing on saturdays" typed in the focus box
 * wasn't followed. "Instructions for the AI" (mockup generate-plan.html):
 * a must-have the app guarantees and days off the app enforces.
 */
test('a must-have and a day off from the dialog hold in the plan, whatever the AI answers', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.structured' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);

    $user = User::factory()->weekStartsOn(WeekStart::Monday)->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    // A coming month: the dialog opens on its first 7 days.
    $month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $mustDay = $month->addDays(2);
    $dayOff = $month->addDays(4);
    expect($mustDay->dayOfWeek)->not->toBe($dayOff->dayOfWeek);
    $plan = ContentPlan::factory()->create(['workspace_id' => $workspace->id, 'month' => $month->toDateString()]);

    // The AI ignores the must-have and puts its one idea on the day off.
    fakePlannerWeeks([[
        'date' => $dayOff->toDateString(),
        'event_key' => '',
        'channel' => $linkedin->id,
        'content_type' => ContentType::LinkedInPost->value,
        'creative' => 'image',
        'title' => 'Payroll myths, busted',
        'pillar' => '',
        'objective' => 'Clear up a common payroll myth.',
        'angle' => 'Three myths, one slide each.',
        'suggested_time' => '',
        'must_have' => '',
    ]]);

    $this->actingAs($user->fresh());
    $page = visit(route('socialhq.plan.show', PlanMonth::key($month)))->resize(1440, 900);
    waitFor($page, '@plan-generate');
    $page->click('@plan-generate');
    waitFor($page, '@plan-generate-dialog');
    waitFor($page, '@plan-generate-instructions');

    $page->fill('@plan-generate-goals', 'Content about the brand more than insights.');
    $page->click('@plan-generate-must-have-add');
    waitFor($page, '@plan-generate-must-have-0');
    $page->select('@plan-generate-must-have-day-0', $mustDay->toDateString());
    $page->fill('@plan-generate-must-have-focus-0', 'Month-end payroll closing checklist');
    $page->click("@plan-generate-day-off-{$dayOff->dayOfWeek}");
    waitForState($page, "@plan-generate-day-off-{$dayOff->dayOfWeek}", "element.getAttribute('aria-pressed') === 'true'");

    $page->click('@plan-generate-start');
    waitForGone($page, '@plan-generate-dialog');
    waitForDatabase($page, fn (): bool => $plan->fresh()->generated_at !== null);

    $plan = $plan->fresh();
    $mustHave = $plan->items()->whereNotNull('must_have')->sole();

    expect($plan->goals)->toBe('Content about the brand more than insights.')
        ->and($plan->generation_options['days_off'])->toBe([$dayOff->dayOfWeek])
        ->and($plan->generation_options['must_haves'])->toBe([['date' => $mustDay->toDateString(), 'channel' => null, 'content_type' => null, 'focus' => 'Month-end payroll closing checklist']])
        // The app added the must-have the AI missed, on its day.
        ->and([$mustHave->scheduled_on->toDateString(), $mustHave->social_account_id, $mustHave->title])->toBe([$mustDay->toDateString(), $linkedin->id, 'Month-end payroll closing checklist'])
        ->and($plan->items()->whereDate('scheduled_on', $dayOff->toDateString())->exists())->toBeFalse();

    // After a reload the plan shows the must-have with its badge, and nothing on the day off.
    $page->navigate(route('socialhq.plan.show', PlanMonth::key($month)));
    waitFor($page, "@plan-item-must-have-{$mustHave->id}");
    $page->assertVisible("@plan-item-must-have-{$mustHave->id}");
    waitFor($page, '@plan-generation-must-haves-added');
    $page->assertSeeIn('@plan-generation-must-haves-added', __('socialhq_plan.generation.must_haves_added', ['count' => 1]));
    expect($page->script('document.querySelectorAll('.json_encode("[data-testid=\"plan-day-{$dayOff->toDateString()}\"] [data-status]").').length'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});
