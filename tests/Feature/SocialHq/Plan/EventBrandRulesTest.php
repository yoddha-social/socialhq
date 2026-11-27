<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\BrandEventCountries;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventFlag;
use App\SocialHq\Events\EventHide;
use App\SocialHq\Events\EventRegion;
use App\SocialHq\Events\EventsForMonth;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\PlanOptions;
use Carbon\CarbonImmutable;

/*
 * Owner decisions 2026-11-24: a brand plans around the countries it follows
 * (a brand abroad never sees Nepal's dates), its approvers hide shared dates
 * it has no use for, and members flag a date that looks wrong. QA 2026-11-18,
 * item 6: one New Year, Undo for "Confirm all shown".
 */

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->account = $this->owner->account;
    $this->workspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->member = workspaceMember($this->workspace, 'approval');
    $this->day = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(14);
    $this->on = fn (array $attributes = []): CalendarEvent => CalendarEvent::factory()->create([
        'account_id' => $this->account->id,
        'starts_on' => $this->day,
        'ends_on' => $this->day,
        ...$attributes,
    ]);
    $this->shown = fn (?Workspace $workspace = null): array => EventsForMonth::forWorkspace($workspace ?? $this->workspace, $this->day, $this->day)
        ->pluck('id')->sort()->values()->all();
});

test('a brand follows Nepal until it has an audience, then its audience\'s countries, then its own choice', function () {
    expect(BrandEventCountries::for($this->workspace))->toBe(['NP']);

    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'audience_locations' => [
        ['country' => 'AU', 'city' => 'Sydney', 'timezone' => 'Australia/Sydney', 'share' => 80],
    ]]);
    expect(BrandEventCountries::for($this->workspace))->toBe(['AU']);

    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.brand-countries.update'), ['countries' => ['au', 'US']])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.success', __('socialhq_events.brand_countries.saved'));
    expect(BrandEventCountries::for($this->workspace))->toBe(['AU', 'US']);

    // No country at all: international days and the brand's own dates only.
    $this->actingAs($this->owner)->put(route('socialhq.plan.events.brand-countries.update'), ['countries' => []])->assertSessionHasNoErrors();
    expect(BrandEventCountries::for($this->workspace))->toBe([]);
});

test('only workspace admins choose the countries', function () {
    $this->actingAs($this->member)
        ->put(route('socialhq.plan.events.brand-countries.update'), ['countries' => ['US']])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.brand-countries.update'), ['countries' => ['Nepal']])
        ->assertSessionHasErrors('countries.0');
});

test('a brand abroad sees no Nepali dates on its events page, in its plan or for the planner', function () {
    BrandEventCountries::save($this->workspace, ['AU']);
    $nepal = ($this->on)(['country_code' => 'NP', 'region' => EventRegion::Nepal]);
    $australia = ($this->on)(['country_code' => 'AU', 'region' => EventRegion::Country]);
    $international = ($this->on)(['country_code' => null, 'region' => EventRegion::International]);
    $own = ($this->on)(['workspace_id' => $this->workspace->id, 'region' => EventRegion::Brand, 'country_code' => 'NP']);

    $expected = collect([$australia->id, $international->id, $own->id])->sort()->values()->all();

    expect(($this->shown)())->toBe($expected)
        ->and(PlanOptions::events($this->workspace, $this->day->startOfMonth(), $this->day->endOfMonth())->pluck('id')->sort()->values()->all())->toBe($expected)
        ->and(EventsForMonth::markersByDay($this->workspace, $this->day, $this->day)[$this->day->toDateString()])->toHaveCount(3);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $this->day->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->where('brandCountries', ['codes' => ['AU'], 'chosen' => true])
            ->where('events', fn ($events) => ! collect($events)->pluck('id')->contains($nepal->id)));

    // Another brand of the account that follows Nepal still sees it.
    $other = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    expect(($this->shown)($other))->toContain($nepal->id);
});

test('the official import brings Nepal\'s dates only for a brand that follows Nepal', function () {
    BrandEventCountries::save($this->workspace, ['AU']);

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.import'))->assertRedirect();

    expect(CalendarEvent::query()->where('account_id', $this->account->id)->where('country_code', 'NP')->exists())->toBeFalse()
        ->and(CalendarEvent::query()->where('account_id', $this->account->id)->whereNull('country_code')->exists())->toBeTrue();
});

test('a shared date added by hand takes the first country the brand follows', function () {
    BrandEventCountries::save($this->workspace, ['AU']);

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), [
        'name' => 'Melbourne Cup', 'name_ne' => null, 'category' => 'festival',
        'starts_on' => $this->day->toDateString(), 'ends_on' => $this->day->toDateString(),
        'bs_date' => null, 'public_holiday' => false, 'description' => null, 'source_url' => null, 'brand_only' => false,
    ])->assertSessionHasNoErrors();

    expect(CalendarEvent::query()->where('name', 'Melbourne Cup')->sole()->only('region', 'country_code'))
        ->toBe(['region' => EventRegion::Country, 'country_code' => 'AU']);
});

test('an approver hides a shared date for this brand only, and shows it again', function () {
    $shared = ($this->on)(['country_code' => null, 'region' => EventRegion::International]);
    $other = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    EventFlag::query()->create(['workspace_id' => $this->workspace->id, 'event_id' => $shared->id, 'user_id' => $this->member->id, 'note' => 'Not for us']);

    $this->actingAs($this->member)->post(route('socialhq.plan.events.hide', $shared->id))->assertForbidden();
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.hide', $shared->id))
        ->assertSessionHas('flash.success', __('socialhq_events.hide.done', ['name' => $shared->name]));

    expect(($this->shown)())->not->toContain($shared->id)
        ->and(($this->shown)($other))->toContain($shared->id)
        ->and(EventFlag::query()->sole()->resolved_at)->not->toBeNull();

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $this->day->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->has('hidden', 1)->where('hidden.0.id', $shared->id));

    $this->actingAs($this->owner)->delete(route('socialhq.plan.events.unhide', $shared->id))->assertSessionHasNoErrors();
    expect(($this->shown)())->toContain($shared->id)
        ->and(EventHide::query()->count())->toBe(0);
});

test('a brand\'s own date is deleted, not hidden', function () {
    $own = ($this->on)(['workspace_id' => $this->workspace->id, 'region' => EventRegion::Brand]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.hide', $own->id))
        ->assertSessionHasErrors(['event' => __('socialhq_events.hide.own_date')]);
});

test('a member flags a date; approvers see it on the date and in Waiting for you, and confirming it handles the flag', function () {
    $date = ($this->on)(['country_code' => 'NP', 'region' => EventRegion::Nepal]);

    $this->actingAs($this->member)->post(route('socialhq.plan.events.flags.store', $date->id), ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->member)->post(route('socialhq.plan.events.flags.store', $date->id), ['note' => 'Tihar is a day earlier.'])
        ->assertSessionHas('flash.success', __('socialhq_events.flag.sent'));

    $flag = EventFlag::query()->sole();

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $this->day->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->where('events.0.rows.0.flags.0.note', 'Tihar is a day earlier.')
            ->where('events.0.rows.0.flags.0.by', $this->member->name));
    $this->actingAs($this->owner)
        ->get(route('socialhq.inbox'))
        ->assertInertia(fn ($page) => $page->has('sections.event_flags', 1)->where('sections.event_flags.0.name', $date->name));
    $this->actingAs($this->member)
        ->get(route('socialhq.inbox'))
        ->assertInertia(fn ($page) => $page->has('sections.event_flags', 0));

    // Only approvers handle flags.
    $this->actingAs($this->member)->delete(route('socialhq.plan.events.flags.destroy', $flag->id))->assertForbidden();

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.verify', $date->id), [
        'name' => $date->name, 'starts_on' => $date->starts_on->toDateString(), 'ends_on' => $date->ends_on->toDateString(),
    ])->assertSessionHas('flash.success', __('socialhq_events.confirm.done'));

    expect($flag->fresh()->resolved_by)->toBe($this->owner->id);
});

test('an approver marks a flag handled', function () {
    $date = ($this->on)();
    $flag = EventFlag::query()->create(['workspace_id' => $this->workspace->id, 'event_id' => $date->id, 'user_id' => $this->member->id, 'note' => 'Wrong year?']);

    $this->actingAs($this->owner)->delete(route('socialhq.plan.events.flags.destroy', $flag->id))->assertSessionHasNoErrors();

    expect($flag->fresh()->resolved_at)->not->toBeNull();
});

test('a country\'s holiday that is also the international day is one event, with a row for each', function () {
    BrandEventCountries::save($this->workspace, ['NP', 'AU']);
    $international = ($this->on)(['key' => 'new-years-day', 'name' => 'New Year\'s Day', 'country_code' => null, 'region' => EventRegion::International]);
    $australia = ($this->on)(['key' => 'new-years-day', 'name' => 'New Year\'s Day', 'country_code' => 'AU', 'region' => EventRegion::Country]);

    expect(($this->shown)())->toBe([$international->id])
        ->and(EventsForMonth::markersByDay($this->workspace, $this->day, $this->day)[$this->day->toDateString()])->toHaveCount(1)
        ->and(EventsForMonth::markersByDay($this->workspace, $this->day, $this->day)[$this->day->toDateString()][0]['countries'])->toBe(['AU']);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $this->day->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->has('events', 1)
            ->where('events.0.rows.0.id', $international->id)
            ->where('events.0.rows.1.id', $australia->id));

    // Australia's date is a row of its own now, so Australia's chip confirms it.
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.verify-shown'), ['view' => 'month', 'month' => $this->day->format('Y-m'), 'filter' => 'country', 'country' => 'AU'])
        ->assertSessionHas('flash.eventsConfirmed.ids', [$australia->id]);

    expect($international->fresh()->isVerified())->toBeFalse();
});

test('confirm all shown can be undone, leaving dates confirmed since by someone else', function () {
    $first = ($this->on)();
    $second = ($this->on)();

    $response = $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.verify-shown'), ['view' => 'month', 'month' => $this->day->format('Y-m'), 'filter' => 'all'])
        ->assertSessionHas('flash.eventsConfirmed.ids', fn (array $ids): bool => collect($ids)->sort()->values()->all() === collect([$first->id, $second->id])->sort()->values()->all());
    $batch = $response->getSession()->get('flash.eventsConfirmed');

    // Someone else confirmed the second one again meanwhile.
    $second->forceFill(['verified_by' => $this->member->id])->saveQuietly();

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.verify-shown.undo'), ['ids' => $batch['ids'], 'at' => $batch['at']])
        ->assertSessionHas('flash.success', trans_choice('socialhq_events.confirm_shown.undone', 1, ['count' => 1]));

    expect($first->fresh()->isVerified())->toBeFalse()
        ->and($second->fresh()->isVerified())->toBeTrue();
});
