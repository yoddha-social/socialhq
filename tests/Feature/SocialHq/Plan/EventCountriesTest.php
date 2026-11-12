<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventCategory;
use App\SocialHq\Events\EventRegion;
use App\SocialHq\Events\EventsForMonth;
use App\SocialHq\Events\ImportOfficialEvents;
use App\SocialHq\Events\ImportPublicHolidays;
use App\SocialHq\Events\Nager\NagerDateUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->account = $this->owner->account;
    $this->workspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->year = CarbonImmutable::now('UTC')->year;
    $this->host = config('socialhq.events.nager.host');

    // What Nager.Date answers: the country list, then per "{year}/{country}" (a missing key is a 404; "down" fails to connect).
    $this->nager = [
        'countries' => [['countryCode' => 'US', 'name' => 'United States'], ['countryCode' => 'DE', 'name' => 'Germany'], ['countryCode' => 'NP', 'name' => 'Nepal']],
        "{$this->year}/US" => [
            nagerHoliday("{$this->year}-07-04", 'Independence Day'),
            nagerHoliday("{$this->year}-03-31", 'César Chávez Day', global: false, counties: ['US-CA']),
            nagerHoliday("{$this->year}-03-31", 'César Chávez Day', global: false, counties: ['US-TX', 'US-CA']),
            nagerHoliday("{$this->year}-10-12", 'Columbus Day', types: ['Bank', 'School']),
            nagerHoliday("{$this->year}-13-45", 'Broken'),
        ],
        "{$this->year}/DE" => [
            nagerHoliday("{$this->year}-01-01", "New Year's Day", localName: 'Neujahr'),
        ],
    ];
    $nager = &$this->nager;
    $host = $this->host;
    Http::fake(function (Request $request) use (&$nager, $host) {
        $key = $request->url() === "{$host}/api/v3/AvailableCountries" ? 'countries' : str_replace("{$host}/api/v3/PublicHolidays/", '', $request->url());

        return match (true) {
            ! array_key_exists($key, $nager) => Http::response(null, 404),
            $nager[$key] === 'down' => Http::failedConnection(),
            default => Http::response($nager[$key]),
        };
    });
    Http::preventStrayRequests();
});

/**
 * One holiday as Nager.Date's API v3 lists it.
 *
 * @param  list<string>  $types
 * @param  list<string>|null  $counties
 * @return array<string, mixed>
 */
function nagerHoliday(string $date, string $name, ?string $localName = null, bool $global = true, ?array $counties = null, array $types = ['Public']): array
{
    return [
        'date' => $date,
        'localName' => $localName ?? $name,
        'name' => $name,
        'countryCode' => 'XX',
        'fixed' => false,
        'global' => $global,
        'counties' => $counties,
        'launchYear' => null,
        'types' => $types,
    ];
}

test('a country\'s public holidays are imported unconfirmed, from Nager.Date', function () {
    $counts = ImportPublicHolidays::handle($this->account, ['us', 'DE'], [$this->year]);

    expect($counts)->toBe(['created' => 4, 'updated' => 0, 'skipped' => 0, 'missing' => 0])
        ->and(CalendarEvent::query()->whereNotNull('verified_at')->exists())->toBeFalse()
        ->and(CalendarEvent::query()->whereNotNull('workspace_id')->exists())->toBeFalse();

    $independence = CalendarEvent::query()->where('key', 'independence-day')->sole();
    expect($independence->country_code)->toBe('US')
        ->and($independence->region)->toBe(EventRegion::Country)
        ->and($independence->category)->toBe(EventCategory::PublicHoliday)
        ->and($independence->public_holiday)->toBeTrue()
        ->and($independence->name_ne)->toBeNull()
        ->and($independence->starts_on->toDateString())->toBe("{$this->year}-07-04")
        ->and($independence->ends_on->toDateString())->toBe("{$this->year}-07-04")
        ->and($independence->source_name)->toBe('Nager.Date')
        ->and($independence->source_url)->toBe("{$this->host}/api/v3/PublicHolidays/{$this->year}/US")
        ->and($independence->description)->toBeNull();

    // Listed once per group of states: one row, every state named.
    $chavez = CalendarEvent::query()->where('key', 'cesar-chavez-day')->sole();
    expect($chavez->description)->toBe('Only in: US-CA, US-TX.')
        ->and($chavez->public_holiday)->toBeTrue();

    $columbus = CalendarEvent::query()->where('key', 'columbus-day')->sole();
    expect($columbus->category)->toBe(EventCategory::Observance)
        ->and($columbus->public_holiday)->toBeFalse()
        ->and($columbus->description)->toBe('A day off for banks, schools only.');

    $newYear = CalendarEvent::query()->where('country_code', 'DE')->sole();
    expect($newYear->name)->toBe("New Year's Day")
        ->and($newYear->name_ne)->toBe('Neujahr');
});

test('re-importing keeps confirmations, updates changed holidays and skips the ones a person edited', function () {
    ImportPublicHolidays::handle($this->account, ['US'], [$this->year]);

    $independence = CalendarEvent::query()->where('key', 'independence-day')->sole();
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.verify', $independence->id))->assertRedirect();

    $chavez = CalendarEvent::query()->where('key', 'cesar-chavez-day')->sole();
    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put(route('socialhq.plan.events.update', $chavez->id), [
        'name' => $chavez->name,
        'name_ne' => null,
        'category' => 'public_holiday',
        'starts_on' => $chavez->starts_on->toDateString(),
        'ends_on' => $chavez->ends_on->toDateString(),
        'bs_date' => null,
        'public_holiday' => true,
        'description' => 'Our note.',
        'source_url' => $chavez->source_url,
        'brand_only' => false,
    ])->assertSessionHasNoErrors();

    // Nager.Date changes Columbus Day and the State holiday; a new day later.
    Cache::flush();
    $this->nager["{$this->year}/US"][3] = nagerHoliday("{$this->year}-10-12", 'Columbus Day', types: ['Public']);
    $this->nager["{$this->year}/US"][1] = nagerHoliday("{$this->year}-03-31", 'César Chávez Day', global: false, counties: ['US-CO']);

    expect(ImportPublicHolidays::handle($this->account, ['US'], [$this->year]))->toBe(['created' => 0, 'updated' => 1, 'skipped' => 2, 'missing' => 0])
        ->and($independence->fresh()->isVerified())->toBeTrue()
        ->and($chavez->fresh()->description)->toBe('Our note.')
        ->and(CalendarEvent::query()->where('key', 'columbus-day')->sole()->category)->toBe(EventCategory::PublicHoliday)
        ->and(CalendarEvent::query()->where('country_code', 'US')->count())->toBe(3);
});

test('a holiday the team deleted stays deleted, and one Nager.Date stopped listing is counted', function () {
    ImportPublicHolidays::handle($this->account, ['US'], [$this->year]);
    CalendarEvent::query()->where('key', 'independence-day')->sole()->delete();

    Cache::flush();
    $this->nager["{$this->year}/US"] = array_values(array_filter($this->nager["{$this->year}/US"], fn (array $holiday): bool => $holiday['name'] !== 'Columbus Day'));

    expect(ImportPublicHolidays::handle($this->account, ['US'], [$this->year]))->toBe(['created' => 0, 'updated' => 0, 'skipped' => 2, 'missing' => 1])
        ->and(CalendarEvent::query()->where('key', 'independence-day')->exists())->toBeFalse()
        ->and(CalendarEvent::query()->where('key', 'columbus-day')->exists())->toBeTrue();
});

test('each country and year is read from Nager.Date once, then from the cache', function () {
    ImportPublicHolidays::handle($this->account, ['US'], [$this->year]);
    ImportPublicHolidays::handle($this->account, ['US'], [$this->year]);

    Http::assertSentCount(1);
});

test('a country Nager.Date can\'t answer for stops the whole import', function () {
    expect(fn () => ImportPublicHolidays::handle($this->account, ['US', 'FR'], [$this->year]))
        ->toThrow(fn (NagerDateUnavailable $exception) => expect($exception->countries)->toBe(['FR']));

    expect(CalendarEvent::query()->count())->toBe(0);
});

test('Nepal\'s holidays never come from Nager.Date', function () {
    expect(fn () => ImportPublicHolidays::handle($this->account, ['NP'], [$this->year]))->toThrow(InvalidArgumentException::class);

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.countries.store'), ['countries' => ['np'], 'years' => [$this->year]])
        ->assertSessionHasErrors(['countries.0' => __('socialhq_events.countries.errors.nepal')]);

    Http::assertNothingSent();
});

test('the official list and other countries\' holidays don\'t touch each other', function () {
    ImportPublicHolidays::handle($this->account, ['US'], [$this->year]);
    $official = ImportOfficialEvents::handle($this->account);

    expect($official['missing'])->toBe(0)
        ->and(CalendarEvent::query()->where('country_code', 'US')->count())->toBe(3)
        ->and(CalendarEvent::query()->where('country_code', 'NP')->where('region', EventRegion::Nepal)->count())->toBeGreaterThan(50)
        ->and(CalendarEvent::query()->whereNull('country_code')->where('region', EventRegion::International)->count())->toBeGreaterThan(50);

    expect(ImportOfficialEvents::handle($this->account)['created'])->toBe(0)
        ->and(ImportPublicHolidays::handle($this->account, ['US'], [$this->year]))->toBe(['created' => 0, 'updated' => 0, 'skipped' => 3, 'missing' => 0]);
});

test('approvers add a country from the events page and see the counts', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.countries.store'), ['countries' => ['US', 'DE'], 'years' => [$this->year]])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.bannerStyle', 'success')
        ->assertSessionHas('flash.banner', __('socialhq_events.countries.done', ['countries' => 'United States and Germany', 'created' => 4, 'updated' => 0, 'skipped' => 0]));

    expect(CalendarEvent::query()->where('account_id', $this->account->id)->count())->toBe(4);
});

test('when Nager.Date is down the page says so and imports nothing', function () {
    $this->nager["{$this->year}/US"] = 'down';
    $this->nager['countries'] = 'down';

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.countries.store'), ['countries' => ['US'], 'years' => [$this->year]])
        ->assertRedirect()
        ->assertSessionHas('flash.bannerStyle', 'danger')
        ->assertSessionHas('flash.banner', __('socialhq_events.countries.unavailable', ['countries' => 'US']));

    expect(CalendarEvent::query()->count())->toBe(0);
});

test('adding a country checks the countries and years', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.countries.store'), ['countries' => [], 'years' => [$this->year - 1]])
        ->assertSessionHasErrors(['countries', 'years.0']);

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.countries.store'), ['countries' => ['US', 'DE', 'FR', 'IN', 'GB', 'JP'], 'years' => [$this->year, $this->year + 1]])
        ->assertSessionHasErrors('countries');

    Http::assertNothingSent();
});

test('only people who may change shared dates add a country', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $brandApprover = workspaceMember($this->workspace, 'admin');
    Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $payload = ['countries' => ['US'], 'years' => [$this->year]];

    $this->actingAs($member)->post(route('socialhq.plan.events.countries.store'), $payload)->assertForbidden();
    $this->actingAs($brandApprover)->post(route('socialhq.plan.events.countries.store'), $payload)->assertForbidden();

    $this->actingAs($brandApprover)->get(route('socialhq.plan.events.index'))
        ->assertInertia(fn ($page) => $page->reloadOnly('holidayCountries', fn ($reload) => $reload->where('holidayCountries', [])));

    expect(CalendarEvent::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('the countries to add load only when asked for, without Nepal', function () {
    $this->actingAs($this->owner)->get(route('socialhq.plan.events.index'))
        ->assertInertia(fn ($page) => $page->missing('holidayCountries')
            ->where('holidayYears', [$this->year, $this->year + 1])
            ->reloadOnly('holidayCountries', fn ($reload) => $reload->where('holidayCountries', [
                ['code' => 'DE', 'name' => 'Germany'],
                ['code' => 'US', 'name' => 'United States'],
            ])));
});

test('the country list is null when Nager.Date can\'t be reached', function () {
    $this->nager['countries'] = 'down';

    $this->actingAs($this->owner)->get(route('socialhq.plan.events.index'))
        ->assertInertia(fn ($page) => $page->reloadOnly('holidayCountries', fn ($reload) => $reload->where('holidayCountries', null)));
});

test('the events page filters by country, keeping dates without one', function () {
    $day = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(9);
    $us = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => 'US', 'region' => EventRegion::Country, 'starts_on' => $day, 'ends_on' => $day]);
    $international = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => null, 'region' => EventRegion::International, 'starts_on' => $day, 'ends_on' => $day]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => 'DE', 'region' => EventRegion::Country, 'starts_on' => $day, 'ends_on' => $day]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => 'NP', 'starts_on' => $day, 'ends_on' => $day]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $day->format('Y-m'), 'country' => 'us']))
        ->assertInertia(fn ($page) => $page->where('country', 'US')
            ->where('countries', ['DE', 'NP', 'US'])
            ->has('events', 2)
            ->where('events', fn ($events) => collect($events)->pluck('id')->sort()->values()->all() === collect([$us->id, $international->id])->sort()->values()->all())
            ->where('events.0.country_code', fn ($code) => in_array($code, ['US', null], true)));

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $day->format('Y-m'), 'country' => 'nonsense']))
        ->assertInertia(fn ($page) => $page->where('country', null)->has('events', 4));
});

test('events for a range can be narrowed to an audience\'s countries', function () {
    $day = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(9);
    $us = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => 'US', 'starts_on' => $day, 'ends_on' => $day]);
    $nepal = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => 'NP', 'starts_on' => $day, 'ends_on' => $day]);
    $international = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => null, 'starts_on' => $day, 'ends_on' => $day]);
    $brand = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'workspace_id' => $this->workspace->id, 'country_code' => null, 'starts_on' => $day, 'ends_on' => $day]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'country_code' => 'DE', 'starts_on' => $day, 'ends_on' => $day]);

    $ids = fn (?array $countries): array => EventsForMonth::forWorkspace($this->workspace, $day, $day, $countries)->pluck('id')->sort()->values()->all();

    expect($ids(['us', 'NP']))->toBe(collect([$us->id, $nepal->id, $international->id, $brand->id])->sort()->values()->all())
        ->and($ids([]))->toBe(collect([$international->id, $brand->id])->sort()->values()->all())
        ->and($ids(null))->toHaveCount(5)
        ->and(EventsForMonth::forWorkspace($this->workspace, $day, $day))->toHaveCount(5)
        ->and(EventsForMonth::markersByDay($this->workspace, $day, $day, ['DE'])[$day->toDateString()])->toHaveCount(3);
});

test('a date added by hand gets its country from its region, and may share a name with another country\'s holiday', function () {
    $day = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(9);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'key' => 'harvest-day', 'name' => 'Harvest Day', 'country_code' => 'US', 'region' => EventRegion::Country, 'starts_on' => $day, 'ends_on' => $day]);
    $payload = ['name' => 'Harvest Day', 'name_ne' => null, 'starts_on' => $day->toDateString(), 'ends_on' => $day->toDateString(), 'bs_date' => null, 'public_holiday' => false, 'description' => null, 'source_url' => null, 'brand_only' => false];

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), [...$payload, 'category' => 'festival'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), [...$payload, 'name' => 'Kindness Day', 'category' => 'international_day'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), [...$payload, 'category' => 'festival'])->assertSessionHasErrors('name');

    expect(CalendarEvent::query()->whereNotNull('created_by')->where('key', 'harvest-day')->sole()->country_code)->toBe('NP')
        ->and(CalendarEvent::query()->where('key', 'kindness-day')->sole()->country_code)->toBeNull();
});

test('editing a country\'s holiday keeps its country and region', function () {
    ImportPublicHolidays::handle($this->account, ['US'], [$this->year]);
    $independence = CalendarEvent::query()->where('key', 'independence-day')->sole();
    $payload = ['name' => 'Independence Day', 'name_ne' => null, 'category' => 'public_holiday', 'starts_on' => $independence->starts_on->toDateString(), 'ends_on' => $independence->ends_on->toDateString(), 'bs_date' => null, 'public_holiday' => true, 'description' => null, 'source_url' => null];

    $this->actingAs($this->owner)->put(route('socialhq.plan.events.update', $independence->id), [...$payload, 'brand_only' => true])->assertSessionHasNoErrors();
    expect($independence->fresh())->region->toBe(EventRegion::Brand)->country_code->toBe('US');

    $this->actingAs($this->owner)->put(route('socialhq.plan.events.update', $independence->id), [...$payload, 'brand_only' => false])->assertSessionHasNoErrors();
    expect($independence->fresh())->region->toBe(EventRegion::Country)->country_code->toBe('US');
});

test('the import command imports a country\'s holidays', function () {
    $this->artisan('socialhq:events:import', ['--country' => ['US'], '--year' => [(string) $this->year]])->assertSuccessful();
    $this->artisan('socialhq:events:import', ['--country' => ['NP']])->assertFailed();

    expect(CalendarEvent::query()->where('country_code', 'US')->count())->toBe(3);
});

test('the backfill gives Nepal\'s dates their country', function () {
    $nepal = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'region' => EventRegion::Nepal, 'country_code' => null]);
    $international = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'region' => EventRegion::International, 'country_code' => null]);
    $brand = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'workspace_id' => $this->workspace->id, 'region' => EventRegion::Brand, 'country_code' => null]);

    (require database_path('migrations/socialhq/2026_11_09_000310_backfill_country_code_on_shq_events_table.php'))->up();

    expect($nepal->fresh()->country_code)->toBe('NP')
        ->and($international->fresh()->country_code)->toBeNull()
        ->and($brand->fresh()->country_code)->toBeNull();
});
