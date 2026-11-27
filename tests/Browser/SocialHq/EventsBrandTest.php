<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\BrandEventCountries;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventFlag;
use App\SocialHq\Events\EventRegion;
use Carbon\CarbonImmutable;

/*
 * Owner decisions 2026-11-24 and QA 2026-11-18, item 6, on the events page:
 * a chip per country the brand follows, members flag a date, approvers hide a
 * shared one for this brand, and "Confirm all shown" offers Undo. Owner
 * decision 2026-11-27: the same holiday in two countries is one event with a
 * row per country, each confirmed on its own.
 */

/**
 * @return array{0: User, 1: Workspace, 2: CarbonImmutable}
 */
function eventsBrandWorkspace(): array
{
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($owner->account);
    BrandEventCountries::save($workspace, ['NP', 'AU']);

    return [$owner->fresh(), $workspace, CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(14)];
}

function eventsBrandDate(Workspace $workspace, CarbonImmutable $day, ?string $country, string $name): CalendarEvent
{
    return CalendarEvent::factory()->create([
        'account_id' => $workspace->account_id,
        'name' => $name,
        'country_code' => $country,
        'region' => match ($country) {
            null => EventRegion::International,
            'NP' => EventRegion::Nepal,
            default => EventRegion::Country,
        },
        'starts_on' => $day,
        'ends_on' => $day,
    ]);
}

function visitEventsMonth(CarbonImmutable $day): mixed
{
    $page = visit(route('socialhq.plan.events.index', ['month' => $day->format('Y-m')]))->resize(1440, 900);
    waitFor($page, '@socialhq-events-filters');

    return $page;
}

test('each country the brand follows gets a chip that shows its dates', function () {
    [$owner, $workspace, $day] = eventsBrandWorkspace();
    $nepal = eventsBrandDate($workspace, $day, 'NP', 'Tihar');
    $australia = eventsBrandDate($workspace, $day, 'AU', 'Australia Day');
    $this->actingAs($owner);

    $page = visitEventsMonth($day);
    waitFor($page, '@socialhq-events-filter-country-AU');
    $page->click('@socialhq-events-filter-country-AU');

    waitForGone($page, "@socialhq-event-{$nepal->id}");
    $page->assertVisible("@socialhq-event-{$australia->id}")
        ->assertSeeIn('@socialhq-events-brand-countries', 'Australia')
        ->assertNoJavaScriptErrors();
});

test('a member flags a date with a note', function () {
    [, $workspace, $day] = eventsBrandWorkspace();
    $member = workspaceMember($workspace, 'approval', ['timezone' => 'UTC']);
    $date = eventsBrandDate($workspace, $day, 'NP', 'Tihar');
    $this->actingAs($member);

    $page = visitEventsMonth($day);
    $page->click("@socialhq-event-flag-{$date->id}");
    waitFor($page, '@socialhq-event-flag-dialog');
    $page->fill('@socialhq-event-flag-note', 'Tihar is a day earlier this year.');
    $page->click('@socialhq-event-flag-send');

    waitFor($page, "@socialhq-event-flags-{$date->id}");
    $page->assertSeeIn("@socialhq-event-flags-{$date->id}", 'Tihar is a day earlier this year.')->assertNoJavaScriptErrors();
    expect(EventFlag::query()->sole()->note)->toBe('Tihar is a day earlier this year.');
});

test('an approver hides a shared date for this brand and shows it again', function () {
    [$owner, $workspace, $day] = eventsBrandWorkspace();
    $date = eventsBrandDate($workspace, $day, null, 'Earth Hour');
    $this->actingAs($owner);

    $page = visitEventsMonth($day);
    $page->click("@socialhq-event-menu-{$date->id}");
    waitFor($page, "@socialhq-event-hide-{$date->id}");
    $page->click("@socialhq-event-hide-{$date->id}");

    waitFor($page, '@socialhq-events-hidden');
    waitFor($page, "@socialhq-event-unhide-{$date->id}");
    $page->click("@socialhq-event-unhide-{$date->id}");

    waitForGone($page, '@socialhq-events-hidden');
    $page->assertVisible("@socialhq-event-{$date->id}")->assertNoJavaScriptErrors();
});

test('confirm all shown offers Undo, which takes the confirmations back', function () {
    [$owner, $workspace, $day] = eventsBrandWorkspace();
    $first = eventsBrandDate($workspace, $day, 'NP', 'Tihar');
    $second = eventsBrandDate($workspace, $day, null, 'Earth Hour');
    $this->actingAs($owner);

    $page = visitEventsMonth($day);
    $page->click('@socialhq-events-confirm-shown');
    waitFor($page, '@socialhq-events-confirm-shown-confirm');
    $page->click('@socialhq-events-confirm-shown-confirm');

    waitFor($page, '@socialhq-events-confirmed-toast');
    waitForState($page, "@socialhq-event-{$first->id}", "element.dataset.verified === 'true'");
    $page->click('[data-testid="socialhq-events-confirmed-toast"] [data-button]');

    waitForState($page, "@socialhq-event-{$first->id}", "element.dataset.verified === 'false'");
    expect($first->fresh()->isVerified())->toBeFalse()
        ->and($second->fresh()->isVerified())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('a holiday of two countries is one event with a row each, and confirming one country leaves the other to check', function () {
    [$owner, $workspace, $day] = eventsBrandWorkspace();
    $australia = eventsBrandDate($workspace, $day, 'AU', 'Christmas Day');
    $nepal = eventsBrandDate($workspace, $day, 'NP', 'Christmas Day');
    $this->actingAs($owner);

    $page = visitEventsMonth($day);
    waitFor($page, "@socialhq-event-group-{$australia->id} @socialhq-event-{$nepal->id}");
    waitForText($page, "@socialhq-event-group-summary-{$australia->id}", '0 of 2 countries confirmed');
    $page->click("@socialhq-event-confirm-{$nepal->id}");

    waitForDatabase($page, fn (): bool => $nepal->fresh()->isVerified());
    waitForState($page, "@socialhq-event-{$nepal->id}", "element.dataset.verified === 'true'");

    $page = visitEventsMonth($day);
    waitFor($page, "@socialhq-event-group-{$australia->id}");
    waitForText($page, "@socialhq-event-group-summary-{$australia->id}", '1 of 2 countries confirmed');
    $page->assertVisible("@socialhq-event-confirmed-{$nepal->id}")
        ->assertVisible("@socialhq-event-needs-check-{$australia->id}")
        ->assertVisible("@socialhq-event-confirm-{$australia->id}")
        ->assertNoJavaScriptErrors();
    expect($australia->fresh()->isVerified())->toBeFalse();
});
