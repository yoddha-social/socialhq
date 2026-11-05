<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventCategory;
use App\SocialHq\Events\EventRegion;
use App\SocialHq\Events\EventsForMonth;
use App\SocialHq\Events\ImportOfficialEvents;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use App\Support\Timezone;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->account = $this->owner->account;
    $this->workspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->day = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(14);
});

/**
 * A CSV file in the format of the shipped dataset, with the given rows.
 *
 * @param  list<array<string, string>>  $rows
 */
function eventsCsv(array $rows, bool $nepal = true): string
{
    $columns = $nepal
        ? ['date_ad', 'date_bs', 'name_en', 'name_ne', 'category', 'public_holiday', 'region_or_community', 'source_url', 'source_name', 'notes']
        : ['date_ad', 'name_en', 'category', 'rule', 'source_url', 'notes'];
    $path = tempnam(sys_get_temp_dir(), 'shq-events-').'.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, $columns, escape: '');

    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn (string $column): string => $row[$column] ?? '', $columns), escape: '');
    }

    fclose($handle);

    return $path;
}

function nepalRow(CarbonImmutable $day, array $overrides = []): array
{
    return [
        'date_ad' => $day->toDateString(),
        'date_bs' => '2083 Kartik 1',
        'name_en' => 'Laxmi Puja (Tihar)',
        'name_ne' => 'लक्ष्मी पूजा',
        'category' => 'festival',
        'public_holiday' => 'yes',
        'region_or_community' => '',
        'source_url' => 'https://moha.gov.np/notice.pdf',
        'source_name' => 'MOHA public holiday notice',
        'notes' => '[official] Notice §2.1.',
        ...$overrides,
    ];
}

test('the importer loads the shipped dataset once, and a second run changes nothing', function () {
    $first = ImportOfficialEvents::handle($this->account);

    expect($first['created'])->toBeGreaterThan(150)
        ->and($first['updated'])->toBe(0)
        ->and(CalendarEvent::query()->count())->toBe($first['created'])
        ->and(CalendarEvent::query()->whereNotNull('verified_at')->exists())->toBeFalse()
        ->and(CalendarEvent::query()->whereNotNull('workspace_id')->exists())->toBeFalse();

    expect(ImportOfficialEvents::handle($this->account))->toBe(['created' => 0, 'updated' => 0, 'skipped' => $first['created'], 'missing' => 0]);
});

test('the importer maps the Nepal and international columns', function () {
    $nepal = eventsCsv([
        nepalRow($this->day),
        nepalRow($this->day->addDay(), ['name_en' => 'Sirua Pawani', 'name_ne' => '', 'public_holiday' => 'partial', 'region_or_community' => 'Jhapa and Morang', 'category' => 'observance']),
        nepalRow($this->day->addDays(2), ['name_en' => 'Constitution Day', 'public_holiday' => 'no', 'category' => 'national_day']),
    ]);
    $international = eventsCsv([
        ['date_ad' => $this->day->toDateString(), 'name_en' => 'World Kindness Day', 'category' => 'international_day', 'rule' => 'fixed', 'source_url' => 'https://www.un.org/en/observances/kindness', 'notes' => 'UN day.'],
        ['date_ad' => $this->day->addDays(3)->toDateString(), 'name_en' => 'Black Friday', 'category' => 'commercial', 'rule' => '', 'source_url' => 'https://en.wikipedia.org/wiki/Black_Friday', 'notes' => ''],
        ['date_ad' => $this->day->addDays(4)->toDateString(), 'name_en' => 'Christmas Day', 'category' => 'global_holiday', 'rule' => 'fixed 12-25', 'source_url' => '', 'notes' => ''],
    ], nepal: false);

    expect(ImportOfficialEvents::handle($this->account, [$nepal, $international]))->toBe(['created' => 6, 'updated' => 0, 'skipped' => 0, 'missing' => 0]);

    $laxmi = CalendarEvent::query()->where('key', 'laxmi-puja-tihar')->firstOrFail();
    expect($laxmi->region)->toBe(EventRegion::Nepal)
        ->and($laxmi->category)->toBe(EventCategory::Festival)
        ->and($laxmi->public_holiday)->toBeTrue()
        ->and($laxmi->bs_date)->toBe('2083 Kartik 1')
        ->and($laxmi->name_ne)->toBe('लक्ष्मी पूजा')
        ->and($laxmi->starts_on->toDateString())->toBe($this->day->toDateString())
        ->and($laxmi->ends_on->toDateString())->toBe($this->day->toDateString())
        ->and($laxmi->source_name)->toBe('Ministry of Home Affairs holiday notice')
        ->and($laxmi->isVerified())->toBeFalse();

    $sirua = CalendarEvent::query()->where('key', 'sirua-pawani')->firstOrFail();
    expect($sirua->public_holiday)->toBeTrue()
        ->and($sirua->category)->toBe(EventCategory::Observance)
        ->and($sirua->name_ne)->toBeNull()
        ->and($sirua->description)->toContain('Jhapa and Morang');

    expect(CalendarEvent::query()->where('key', 'constitution-day')->value('public_holiday'))->toBeFalse()
        ->and(CalendarEvent::query()->where('key', 'constitution-day')->first()->category)->toBe(EventCategory::NationalDay);

    $kindness = CalendarEvent::query()->where('key', 'world-kindness-day')->firstOrFail();
    expect($kindness->region)->toBe(EventRegion::International)
        ->and($kindness->category)->toBe(EventCategory::InternationalDay)
        ->and($kindness->source_name)->toBe('un.org')
        ->and($kindness->description)->toBe('UN day.');

    expect(CalendarEvent::query()->where('key', 'black-friday')->first()->category)->toBe(EventCategory::Commercial)
        ->and(CalendarEvent::query()->where('key', 'christmas-day')->first()->category)->toBe(EventCategory::Observance);
});

test('an unknown category stops the import before anything is written', function () {
    $file = eventsCsv([nepalRow($this->day), nepalRow($this->day->addDay(), ['name_en' => 'Odd', 'category' => 'party'])]);

    expect(fn () => ImportOfficialEvents::handle($this->account, [$file]))->toThrow(InvalidArgumentException::class, 'line 3');
    expect(CalendarEvent::query()->count())->toBe(0);
});

test('re-importing keeps confirmations, updates changed rows and skips rows a person edited', function () {
    ImportOfficialEvents::handle($this->account, [eventsCsv([
        nepalRow($this->day),
        nepalRow($this->day->addDay(), ['name_en' => 'Bhai Tika']),
        nepalRow($this->day->addDays(2), ['name_en' => 'Chhath']),
    ])]);

    $laxmi = CalendarEvent::query()->where('key', 'laxmi-puja-tihar')->firstOrFail();
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.verify', $laxmi->id))->assertRedirect();

    $edited = CalendarEvent::query()->where('key', 'chhath')->firstOrFail();
    $this->travel(1)->minutes();
    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.update', $edited->id), eventPayload($edited->starts_on, ['name' => 'Chhath', 'category' => 'festival', 'brand_only' => false, 'description' => 'Our own note.']))
        ->assertRedirect();

    $counts = ImportOfficialEvents::handle($this->account, [eventsCsv([
        nepalRow($this->day),
        nepalRow($this->day->addDay(), ['name_en' => 'Bhai Tika', 'notes' => '[official] Corrected note.']),
        nepalRow($this->day->addDays(2), ['name_en' => 'Chhath', 'notes' => 'Changed upstream.']),
    ])]);

    expect($counts)->toBe(['created' => 0, 'updated' => 1, 'skipped' => 2, 'missing' => 0])
        ->and($laxmi->fresh()->isVerified())->toBeTrue()
        ->and(CalendarEvent::query()->where('key', 'bhai-tika')->value('description'))->toContain('Corrected note')
        ->and($edited->fresh()->description)->toBe('Our own note.');

    // The row the importer updated is still the importer's: a later change in the file reaches it too.
    expect(ImportOfficialEvents::handle($this->account, [eventsCsv([
        nepalRow($this->day->addDay(), ['name_en' => 'Bhai Tika', 'notes' => 'Again.']),
    ])]))->toBe(['created' => 0, 'updated' => 1, 'skipped' => 0, 'missing' => 0]);
});

test('the import command loads the dataset into the only account', function () {
    $this->artisan('socialhq:events:import')->assertSuccessful();

    expect(CalendarEvent::query()->where('account_id', $this->account->id)->count())->toBeGreaterThan(150);
});

test('the import command asks which account when there are several', function () {
    Account::factory()->create();

    $this->artisan('socialhq:events:import')->assertFailed();
    $this->artisan('socialhq:events:import', ['--account' => $this->account->id])->assertSuccessful();
});

test('events for a range are the brand\'s visible events in date order', function () {
    $other = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $late = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'starts_on' => $this->day->addDays(3), 'ends_on' => $this->day->addDays(3)]);
    $early = CalendarEvent::factory()->verified()->create(['account_id' => $this->account->id, 'workspace_id' => $this->workspace->id, 'starts_on' => $this->day, 'ends_on' => $this->day]);
    $spanning = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'starts_on' => $this->day->subDays(5), 'ends_on' => $this->day->addDay()]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'workspace_id' => $other->id, 'starts_on' => $this->day, 'ends_on' => $this->day]);
    CalendarEvent::factory()->create(['starts_on' => $this->day, 'ends_on' => $this->day]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'starts_on' => $this->day->addDays(20), 'ends_on' => $this->day->addDays(20)]);

    $events = EventsForMonth::forWorkspace($this->workspace, $this->day, $this->day->addDays(10));

    expect($events->pluck('id')->all())->toBe([$spanning->id, $early->id, $late->id]);
});

/**
 * @return array<string, mixed>
 */
function eventPayload(CarbonImmutable $day, array $overrides = []): array
{
    return [
        'name' => 'Store anniversary',
        'name_ne' => null,
        'category' => 'brand',
        'starts_on' => $day->toDateString(),
        'ends_on' => $day->toDateString(),
        'bs_date' => null,
        'public_holiday' => false,
        'description' => null,
        'source_url' => null,
        'brand_only' => true,
        ...$overrides,
    ];
}

test('members see the month\'s events read-only', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $event = CalendarEvent::factory()->verified($this->owner)->create(['account_id' => $this->account->id, 'starts_on' => $this->day, 'ends_on' => $this->day, 'bs_date' => '2083 Kartik 1']);

    $this->actingAs($member)
        ->get(route('socialhq.plan.events.index', ['month' => $this->day->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->component('socialhq/plan/Events')
            ->where('month', $this->day->format('Y-m'))
            ->where('view', 'month')
            ->where('hasEvents', true)
            ->where('abilities.canApprove', false)
            ->has('events', 1)
            ->where('events.0.id', $event->id)
            ->where('events.0.bs_date', '2083 Kartik 1')
            ->where('events.0.verified', true)
            ->where('events.0.verified_by', $this->owner->name)
            ->where('events.0.brand_only', false));
});

test('the page shows the current month by default and the next 90 days on request', function () {
    $today = CarbonImmutable::now(Timezone::normalize($this->owner->timezone));
    $later = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'starts_on' => $today->addDays(60), 'ends_on' => $today->addDays(60)]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => 'nonsense']))
        ->assertInertia(fn ($page) => $page->where('month', $today->format('Y-m')));

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['view' => 'upcoming']))
        ->assertInertia(fn ($page) => $page->where('view', 'upcoming')
            ->where('from', $today->toDateString())
            ->where('events.0.id', $later->id));
});

test('an empty account shows the first-use state', function () {
    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index'))
        ->assertInertia(fn ($page) => $page->where('hasEvents', false)->has('events', 0));
});

test('approvers add, edit, confirm and delete dates', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.store'), eventPayload($this->day))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $event = CalendarEvent::query()->sole();
    expect($event->workspace_id)->toBe($this->workspace->id)
        ->and($event->region)->toBe(EventRegion::Brand)
        ->and($event->key)->toBe('store-anniversary')
        ->and($event->created_by)->toBe($this->owner->id)
        ->and($event->isVerified())->toBeFalse();

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.verify', $event->id))->assertRedirect();
    expect($event->fresh()->verified_by)->toBe($this->owner->id);

    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.update', $event->id), eventPayload($this->day, ['description' => 'Ten years.', 'brand_only' => false, 'category' => 'international_day']))
        ->assertRedirect();
    $event->refresh();
    expect($event->description)->toBe('Ten years.')
        ->and($event->workspace_id)->toBeNull()
        ->and($event->region)->toBe(EventRegion::International)
        ->and($event->isVerified())->toBeTrue();

    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.update', $event->id), eventPayload($this->day->addDay(), ['brand_only' => false, 'category' => 'international_day']))
        ->assertRedirect();
    expect($event->fresh()->isVerified())->toBeFalse();

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.verify', $event->id));
    $this->actingAs($this->owner)->delete(route('socialhq.plan.events.unverify', $event->id))->assertRedirect();
    expect($event->fresh()->isVerified())->toBeFalse();

    $this->actingAs($this->owner)->delete(route('socialhq.plan.events.destroy', $event->id))->assertRedirect();
    expect(CalendarEvent::query()->exists())->toBeFalse()
        ->and(CalendarEvent::query()->withTrashed()->sole()->trashed())->toBeTrue();
});

test('adding a date checks the dates and refuses a duplicate', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.store'), eventPayload($this->day, ['ends_on' => $this->day->subDay()->toDateString(), 'source_url' => 'javascript:alert(1)']))
        ->assertSessionHasErrors(['ends_on', 'source_url']);

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.store'), eventPayload($this->day, ['ends_on' => $this->day->addDays(40)->toDateString()]))
        ->assertSessionHasErrors('ends_on');

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), eventPayload($this->day))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), eventPayload($this->day))->assertSessionHasErrors('name');

    expect(CalendarEvent::query()->count())->toBe(1);
});

test('members cannot change dates', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $event = CalendarEvent::factory()->create(['account_id' => $this->account->id]);

    $this->actingAs($member)->post(route('socialhq.plan.events.store'), eventPayload($this->day))->assertForbidden();
    $this->actingAs($member)->put(route('socialhq.plan.events.update', $event->id), eventPayload($this->day))->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.plan.events.verify', $event->id))->assertForbidden();
    $this->actingAs($member)->delete(route('socialhq.plan.events.unverify', $event->id))->assertForbidden();
    $this->actingAs($member)->delete(route('socialhq.plan.events.destroy', $event->id))->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.plan.events.import'))->assertForbidden();

    expect($event->fresh()->isVerified())->toBeFalse()
        ->and(CalendarEvent::query()->count())->toBe(1);
});

test('another account\'s or another brand\'s event is not found', function () {
    $foreign = CalendarEvent::factory()->create();
    $otherBrand = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $brandOnly = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'workspace_id' => $otherBrand->id, 'starts_on' => $this->day, 'ends_on' => $this->day]);

    foreach ([$foreign, $brandOnly] as $event) {
        $this->actingAs($this->owner)->post(route('socialhq.plan.events.verify', $event->id))->assertNotFound();
        $this->actingAs($this->owner)->put(route('socialhq.plan.events.update', $event->id), eventPayload($this->day))->assertNotFound();
        $this->actingAs($this->owner)->delete(route('socialhq.plan.events.destroy', $event->id))->assertNotFound();
    }

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $this->day->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->has('events', 0));
    expect($foreign->fresh())->not->toBeNull()
        ->and($brandOnly->fresh())->not->toBeNull();
});

test('approvers import the official dates from the page', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.import'))
        ->assertRedirect()
        ->assertSessionHas('flash.bannerStyle', 'success');

    expect(CalendarEvent::query()->where('account_id', $this->account->id)->count())->toBeGreaterThan(150);
});

test('the publish calendar marks the days of the shown month that have events', function () {
    $verified = CalendarEvent::factory()->verified()->create(['account_id' => $this->account->id, 'name' => 'Laxmi Puja', 'starts_on' => $this->day, 'ends_on' => $this->day->addDay()]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'name' => 'Far away', 'starts_on' => $this->day->addMonthsNoOverflow(3), 'ends_on' => $this->day->addMonthsNoOverflow(3)]);
    CalendarEvent::factory()->create(['name' => 'Other account', 'starts_on' => $this->day, 'ends_on' => $this->day]);

    $this->actingAs($this->owner)
        ->get(route('app.calendar', ['view' => 'month', 'month' => $this->day->toDateString()]))
        ->assertInertia(fn ($page) => $page->component('posts/Calendar')
            ->has('shqEvents', 2)
            ->where("shqEvents.{$this->day->toDateString()}", [['name' => 'Laxmi Puja', 'verified' => true, 'category' => $verified->category->value]])
            ->has("shqEvents.{$this->day->addDay()->toDateString()}", 1));
});

test('people see what a note says about the day, not the dataset\'s research notes', function () {
    ImportOfficialEvents::handle($this->account, [eventsCsv([
        nepalRow($this->day, [
            'source_name' => 'Hamro Patro (calendar site); cross-checked https://nepalicalendar.rat32.com/index.php?year=2083&month=07',
            'notes' => '[calendar site (single source)] Notice §2.2(घ) gives the holiday on the day. Date from rat32 only; chariot pulled through Patan.',
        ]),
    ])]);

    $event = CalendarEvent::query()->firstOrFail();

    expect($event->source_name)->toBe('Hamro Patro, checked against nepalicalendar.rat32.com')
        ->and($event->description)->toBe('Chariot pulled through Patan. Only one calendar gives this date: check it before planning around it.');
});

test('the importer reads a CSV saved with a byte order mark and Windows line endings', function () {
    $path = eventsCsv([nepalRow($this->day), nepalRow($this->day->addDay(), ['name_en' => 'Bhai Tika', 'notes' => 'Sisters mark their brothers.'])]);
    file_put_contents($path, "\u{FEFF}".str_replace("\n", "\r\n", (string) file_get_contents($path)));

    expect(ImportOfficialEvents::handle($this->account, [$path]))->toBe(['created' => 2, 'updated' => 0, 'skipped' => 0, 'missing' => 0]);

    $tika = CalendarEvent::query()->where('key', 'bhai-tika')->firstOrFail();
    expect($tika->starts_on->toDateString())->toBe($this->day->addDay()->toDateString())
        ->and($tika->description)->toBe('Sisters mark their brothers.');
});

test('re-importing never brings back a date the team moved, narrowed to this brand or deleted', function () {
    $file = eventsCsv([
        nepalRow($this->day),
        nepalRow($this->day, ['name_en' => 'Bhai Tika']),
        nepalRow($this->day, ['name_en' => 'Chhath']),
    ]);
    ImportOfficialEvents::handle($this->account, [$file]);
    $this->travel(1)->minutes();

    $moved = CalendarEvent::query()->where('key', 'laxmi-puja-tihar')->firstOrFail();
    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.update', $moved->id), eventPayload($this->day->addDays(2), ['name' => 'Laxmi Puja (Tihar)', 'category' => 'festival', 'brand_only' => false]))
        ->assertSessionHasNoErrors();

    $narrowed = CalendarEvent::query()->where('key', 'bhai-tika')->firstOrFail();
    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.update', $narrowed->id), eventPayload($this->day, ['name' => 'Bhai Tika', 'category' => 'festival', 'brand_only' => true]))
        ->assertSessionHasNoErrors();

    $deleted = CalendarEvent::query()->where('key', 'chhath')->firstOrFail();
    $this->actingAs($this->owner)->delete(route('socialhq.plan.events.destroy', $deleted->id))->assertRedirect();

    expect(ImportOfficialEvents::handle($this->account, [$file]))->toBe(['created' => 0, 'updated' => 0, 'skipped' => 3, 'missing' => 0])
        ->and(CalendarEvent::query()->count())->toBe(2)
        ->and($moved->fresh()->starts_on->toDateString())->toBe($this->day->addDays(2)->toDateString())
        ->and($narrowed->fresh()->workspace_id)->toBe($this->workspace->id);
});

test('an import of the official list counts the imported dates it no longer has', function () {
    ImportOfficialEvents::handle($this->account);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'key' => 'dropped-day', 'import_key' => 'dropped-day', 'import_date' => $this->day, 'starts_on' => $this->day, 'ends_on' => $this->day]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'starts_on' => $this->day, 'ends_on' => $this->day]);

    expect(ImportOfficialEvents::handle($this->account)['missing'])->toBe(1);

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.import'))
        ->assertSessionHas('flash.banner', fn (string $banner): bool => str_contains($banner, 'One date is no longer in the official list'));

    expect(CalendarEvent::query()->where('key', 'dropped-day')->exists())->toBeTrue();
});

test('a deleted date can be added again, and stays off the page', function () {
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), eventPayload($this->day))->assertSessionHasNoErrors();
    $first = CalendarEvent::query()->sole();
    $this->actingAs($this->owner)->delete(route('socialhq.plan.events.destroy', $first->id));

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.events.index', ['month' => $this->day->format('Y-m')]))
        ->assertInertia(fn ($page) => $page->has('events', 0));

    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), eventPayload($this->day))->assertSessionHasNoErrors();
    expect(CalendarEvent::query()->count())->toBe(1);
});

test('a shared date can\'t be of the brand kind', function () {
    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.events.store'), eventPayload($this->day, ['brand_only' => false, 'category' => 'brand']))
        ->assertSessionHasErrors('category');

    expect(CalendarEvent::query()->exists())->toBeFalse();
});

test('names that make no slug get keys of their own', function () {
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), eventPayload($this->day, ['name' => '🎉']))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(route('socialhq.plan.events.store'), eventPayload($this->day, ['name' => '🪔']))->assertSessionHasNoErrors();

    expect(CalendarEvent::query()->pluck('key')->unique())->toHaveCount(2)
        ->and(CalendarEvent::query()->pluck('key')->every(fn (string $key): bool => str_starts_with($key, 'event-')))->toBeTrue();
});

test('the import command says when the account id is not one', function () {
    $this->artisan('socialhq:events:import', ['--account' => 'not-a-uuid'])
        ->expectsOutputToContain('No account with id not-a-uuid')
        ->assertFailed();
});

test('the publish calendar leaves out another brand\'s own dates', function () {
    $otherBrand = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'workspace_id' => $otherBrand->id, 'name' => 'Their launch', 'starts_on' => $this->day, 'ends_on' => $this->day]);
    CalendarEvent::factory()->create(['account_id' => $this->account->id, 'workspace_id' => $this->workspace->id, 'name' => 'Our launch', 'starts_on' => $this->day, 'ends_on' => $this->day]);

    $this->actingAs($this->owner)
        ->get(route('app.calendar', ['view' => 'month', 'month' => $this->day->toDateString()]))
        ->assertInertia(fn ($page) => $page->has('shqEvents', 1)
            ->where("shqEvents.{$this->day->toDateString()}.0.name", 'Our launch')
            ->has("shqEvents.{$this->day->toDateString()}", 1));
});

test('an approver of one brand manages that brand\'s dates but not the dates every brand shares', function () {
    $other = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $approver = workspaceMember($this->workspace, 'admin');
    $shared = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'starts_on' => $this->day, 'ends_on' => $this->day]);
    $own = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'workspace_id' => $this->workspace->id, 'category' => 'brand', 'region' => 'brand', 'starts_on' => $this->day, 'ends_on' => $this->day]);

    $this->actingAs($approver)->post(route('socialhq.plan.events.verify', $shared->id))->assertForbidden();
    $this->actingAs($approver)->delete(route('socialhq.plan.events.destroy', $shared->id))->assertForbidden();
    $this->actingAs($approver)->post(route('socialhq.plan.events.import'))->assertForbidden();
    $this->actingAs($approver)->post(route('socialhq.plan.events.verify', $own->id))->assertRedirect();

    $this->actingAs($approver)->get(route('socialhq.plan.events.index'))
        ->assertInertia(fn ($page) => $page->where('abilities.canApprove', true)->where('abilities.canManageSharedEvents', false));

    // Approving in every brand is enough.
    $other->members()->attach($approver->id, membershipPivot('admin'));
    $this->actingAs($approver->fresh())->post(route('socialhq.plan.events.verify', $shared->id))->assertRedirect();

    expect($shared->fresh()->isVerified())->toBeTrue()
        ->and($own->fresh()->isVerified())->toBeTrue();
});

test('narrowing a shared date to one brand unlinks it from the other brands\' items', function () {
    $other = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->owner->id]);
    $shared = CalendarEvent::factory()->create(['account_id' => $this->account->id, 'starts_on' => $this->day, 'ends_on' => $this->day]);
    $theirs = PlanItem::factory()->create([
        'plan_id' => ContentPlan::factory()->create(['workspace_id' => $other->id])->id,
        'event_id' => $shared->id,
    ]);
    $ours = PlanItem::factory()->create([
        'plan_id' => ContentPlan::factory()->create(['workspace_id' => $this->workspace->id])->id,
        'event_id' => $shared->id,
    ]);

    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.events.update', $shared->id), eventPayload($this->day, ['name' => $shared->name, 'category' => 'festival', 'brand_only' => true]))
        ->assertRedirect();

    expect($theirs->fresh()->event_id)->toBeNull()
        ->and($ours->fresh()->event_id)->toBe($shared->id);
});
