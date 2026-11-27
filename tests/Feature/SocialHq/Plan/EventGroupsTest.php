<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Mcp\Servers\TryPostServer;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Events\BrandEventCountries;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventCategory;
use App\SocialHq\Events\EventRegion;
use App\SocialHq\Events\EventsForMonth;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordPicker;
use App\SocialHq\Knowledge\Keywords\Occasion;
use App\SocialHq\Knowledge\Keywords\PostContext;
use App\SocialHq\Mcp\Tools\Plan\ListPlanEventsTool;
use App\SocialHq\Plan\Ai\PlannerContext;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;
use Illuminate\Testing\Fluent\AssertableJson;

/*
 * Owner decision 2026-11-27: the same holiday on the same day in several
 * countries (Christmas Day for Australia and for Nepal) is one event with a
 * row per country. Each row keeps its own confirmation; the plan, the
 * calendar markers, MCP and the planner get the event once.
 */

beforeEach(function () {
    $this->owner = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    BrandEventCountries::save($this->workspace, ['AU', 'NP']);
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->day = $this->month->addDays(14);

    $this->christmas = fn (?string $country, array $attributes = []): CalendarEvent => CalendarEvent::factory()->create([
        'account_id' => $this->workspace->account_id,
        'key' => 'christmas-day',
        'name' => 'Christmas Day',
        'category' => $country === 'NP' ? EventCategory::Festival : EventCategory::PublicHoliday,
        'region' => match ($country) {
            null => EventRegion::International,
            'NP' => EventRegion::Nepal,
            default => EventRegion::Country,
        },
        'country_code' => $country,
        'public_holiday' => true,
        'starts_on' => $this->day,
        'ends_on' => $this->day,
        ...$attributes,
    ]);
    $this->eventsPage = fn () => $this->actingAs($this->owner)->get(route('socialhq.plan.events.index', ['month' => $this->month->format('Y-m')]));
});

test('the events page shows one event with a row per country, and the plan, the calendar and MCP get it once, naming its countries', function () {
    $australia = ($this->christmas)('AU', ['source_name' => 'Nager.Date']);
    $nepal = ($this->christmas)('NP', ['name_ne' => 'क्रिसमस डे']);
    $nextDay = ($this->christmas)('AU', ['key' => 'boxing-day', 'name' => 'Boxing Day', 'starts_on' => $this->day->addDay(), 'ends_on' => $this->day->addDay()]);

    ($this->eventsPage)()->assertInertia(fn ($page) => $page
        ->has('events', 2)
        ->where('events.0.id', $australia->id)
        ->where('events.0.starts_on', $this->day->toDateString())
        ->has('events.0.rows', 2)
        ->where('events.0.rows.0.id', $australia->id)
        ->where('events.0.rows.0.source_name', 'Nager.Date')
        ->where('events.0.rows.1.id', $nepal->id)
        ->where('events.0.rows.1.name_ne', 'क्रिसमस डे')
        ->where('events.1.rows.0.id', $nextDay->id));

    expect(EventsForMonth::markersByDay($this->workspace, $this->day, $this->day)[$this->day->toDateString()])
        ->toBe([['name' => 'Christmas Day', 'verified' => false, 'category' => 'public_holiday', 'countries' => ['AU', 'NP']]]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->month->format('Y-m')))
        ->assertInertia(fn ($page) => $page
            ->has('events', 2)
            ->where('events.0.id', $australia->id)
            ->where('events.0.ids', [$australia->id, $nepal->id])
            ->where('events.0.countries', ['AU', 'NP'])
            ->where('events.0.confirmed', false));

    TryPostServer::actingAs($this->owner)->tool(ListPlanEventsTool::class, ['month' => $this->month->format('Y-m')])
        ->assertHasNoErrors()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('events', 2)
            ->where('events.0.name', 'Christmas Day')
            ->where('events.0.ids', [$australia->id, $nepal->id])
            ->where('events.0.countries', ['AU', 'NP'])
            ->where('events.0.confirmed', false)
            ->where('events.0.confirmed_countries', [])
            ->etc());
});

test('a brand that follows one country sees its row only, as an event of one country', function () {
    ($this->christmas)('AU');
    $nepal = ($this->christmas)('NP');
    BrandEventCountries::save($this->workspace, ['NP']);

    ($this->eventsPage)()->assertInertia(fn ($page) => $page
        ->has('events', 1)
        ->where('events.0.id', $nepal->id)
        ->has('events.0.rows', 1)
        ->where('events.0.rows.0.country_code', 'NP'));

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->month->format('Y-m')))
        ->assertInertia(fn ($page) => $page->has('events', 1)->where('events.0.ids', [$nepal->id])->where('events.0.countries', ['NP']));
});

test('confirming one country\'s date leaves the other to check; the event counts as confirmed for planning', function () {
    $australia = ($this->christmas)('AU');
    $nepal = ($this->christmas)('NP');

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.verify', $nepal->id), [
        'name' => $nepal->name, 'starts_on' => $this->day->toDateString(), 'ends_on' => $this->day->toDateString(),
    ])->assertSessionHas('flash.success', __('socialhq_events.confirm.done'));

    expect($nepal->fresh()->isVerified())->toBeTrue()
        ->and($australia->fresh()->isVerified())->toBeFalse();

    ($this->eventsPage)()->assertInertia(fn ($page) => $page
        ->has('events', 1)
        // The confirmed row is the one items link to now.
        ->where('events.0.id', $nepal->id)
        ->where('events.0.rows.0.verified', false)
        ->where('events.0.rows.1.verified', true));

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->month->format('Y-m')))
        ->assertInertia(fn ($page) => $page
            ->where('events.0.confirmed', true)
            ->where('events.0.countries', ['AU', 'NP'])
            ->where('events.0.confirmed_countries', ['NP']));

    // "Confirm all shown" still works on the rows: Australia's chip confirms Australia's date only.
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.verify-shown'), ['view' => 'month', 'month' => $this->month->format('Y-m'), 'filter' => 'country', 'country' => 'AU'])
        ->assertSessionHas('flash.eventsConfirmed.ids', [$australia->id]);
});

test('an item may link to any country\'s row of an event', function () {
    $australia = ($this->christmas)('AU', ['verified_at' => now()]);
    $nepal = ($this->christmas)('NP');
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $payload = fn (int $eventId): array => [
        'scheduled_on' => $this->day->toDateString(), 'social_account_id' => $instagram->id, 'content_type' => ContentType::InstagramReel->value,
        'creative' => 'video', 'pillar' => null, 'title' => "Christmas {$eventId}", 'objective' => null, 'angle' => null, 'event_id' => $eventId, 'owner_id' => null,
    ];

    $this->actingAs($this->owner)->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), $payload($nepal->id))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.plan.items.store', $this->month->format('Y-m')), $payload($australia->id))->assertSessionHasNoErrors();

    expect(PlanItem::query()->orderBy('id')->pluck('event_id')->all())->toBe([$nepal->id, $australia->id]);
});

test('the planner gets each event once, naming only the confirmed countries, and links its items to the oldest confirmed row', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.structured' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);
    Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);
    BrandEventCountries::save($this->workspace, ['AU', 'DE', 'NP']);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    ($this->christmas)('NP');
    $australia = ($this->christmas)('AU', ['verified_at' => now()]);
    // Germany's date was added by hand under its own key; the same name makes it the same event.
    ($this->christmas)('DE', ['key' => 'erster-weihnachtstag', 'verified_at' => now()->subHour()]);
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);
    $range = ['from' => $this->day->subDays(3)->toDateString(), 'to' => $this->day->addDays(3)->toDateString()];
    $plan->update(['generation_options' => [...$range, 'old_calendars' => false, 'past_posts' => false, 'audience_events' => false]]);

    $events = collect(PlannerContext::build($plan->fresh(), CarbonImmutable::now('UTC'))['events']);

    expect($events->where('name', 'Christmas Day')->values()->all())->toBe([[
        'key' => 'christmas-day',
        'name' => 'Christmas Day',
        'category' => 'public_holiday',
        'starts_on' => $this->day->toDateString(),
        'ends_on' => $this->day->toDateString(),
        'confirmed' => true,
        'countries' => ['AU', 'DE'],
    ]]);

    $suggestion = fn (array $overrides): array => [
        'date' => $this->day->toDateString(), 'channel' => $instagram->id, 'content_type' => ContentType::InstagramReel->value, 'creative' => 'video',
        'pillar' => '', 'title' => 'Christmas greetings', 'objective' => 'Wish everyone well.', 'angle' => 'The team in hats.', 'suggested_time' => '',
        ...$overrides,
    ];
    fakePlannerWeeks([
        $suggestion(['event_key' => 'christmas-day']),
        // The grouped event answers to each of its rows' keys.
        $suggestion(['event_key' => 'erster-weihnachtstag', 'channel' => $linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'title' => 'Christmas on LinkedIn']),
    ]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->month->format('Y-m')), $range)->assertSessionHasNoErrors();

    expect($plan->items()->orderBy('title')->get(['title', 'event_id'])->map(fn (PlanItem $item): array => [$item->title, $item->event_id])->all())
        ->toBe([['Christmas greetings', $australia->id], ['Christmas on LinkedIn', $australia->id]]);
});

test('one occasion tag covers every country\'s date of the event, and the tag picker offers it once', function () {
    $day = CarbonImmutable::today()->addDays(20);
    ($this->christmas)('AU', ['starts_on' => $day, 'ends_on' => $day]);
    // Renamed by hand after it was created: its key is another, the name the same.
    ($this->christmas)('NP', ['key' => 'christmas', 'starts_on' => $day, 'ends_on' => $day]);
    BrandKeywords::save($this->workspace, [
        ['text' => 'christmas wishes', 'main' => false, 'tags' => [['kind' => 'event', 'value' => 'christmas-day', 'label' => 'Christmas Day']]],
        ['text' => 'holiday greetings', 'main' => false, 'tags' => [['kind' => 'event', 'value' => 'christmas', 'label' => 'Christmas']]],
    ]);

    expect(collect(Occasion::choices($this->workspace))->where('name', 'Christmas Day')->pluck('key')->all())->toBe(['christmas-day'])
        ->and(Occasion::activeOn($this->workspace, $day))->toHaveKeys(['christmas-day', 'christmas'])
        ->and(array_column(KeywordPicker::pick($this->workspace, PostContext::forTopic(null, $day->subDays(3))), 'text'))
        ->toContain('christmas wishes', 'holiday greetings');

    // A brand that follows only Nepal still gets the tag through Nepal's date.
    BrandEventCountries::save($this->workspace, ['NP']);

    expect(Occasion::activeOn($this->workspace, $day))->toBe(['christmas' => 'Christmas Day']);
});
