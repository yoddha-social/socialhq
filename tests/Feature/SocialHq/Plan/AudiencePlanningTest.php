<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Calendars\CalendarImport;
use App\SocialHq\Knowledge\Calendars\PastPost;
use App\SocialHq\Plan\Ai\ContentPlanner;
use App\SocialHq\Plan\Ai\PlannerContext;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.structured' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);

    $this->owner = User::factory()->create(['timezone' => 'Asia/Kathmandu']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->member = workspaceMember($this->workspace, 'approval', ['timezone' => 'Asia/Kathmandu']);
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->monthKey = $this->month->format('Y-m');
    $this->day = fn (int $offset): string => $this->month->addDays($offset)->toDateString();
    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->profile = BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'about' => 'A Kathmandu momo chain.']);
    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);

    $this->audience = fn (): array => [
        ['country' => 'np', 'city' => 'Kathmandu', 'timezone' => 'Asia/Kathmandu', 'share' => 30],
        ['country' => 'AU', 'city' => ' Sydney ', 'timezone' => 'Australia/Sydney', 'share' => 50],
    ];

    $this->suggestion = fn (array $overrides = []): array => [
        'date' => ($this->day)(6),
        'channel' => $this->instagram->id,
        'content_type' => ContentType::InstagramReel->value,
        'creative' => 'video',
        'pillar' => '',
        'title' => 'Momo folding time-lapse',
        'objective' => 'Show the handmade process.',
        'angle' => 'Speed-up of the morning prep.',
        'event_key' => '',
        'suggested_time' => '18:30',
        ...$overrides,
    ];
});

test('an admin saves where the audience is, largest share first, and members only read it', function () {
    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.audience.update'), ['locations' => ($this->audience)()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->profile->fresh()->audience_locations)->toEqual([
        ['country' => 'AU', 'city' => 'Sydney', 'timezone' => 'Australia/Sydney', 'share' => 50],
        ['country' => 'NP', 'city' => 'Kathmandu', 'timezone' => 'Asia/Kathmandu', 'share' => 30],
    ]);

    $this->actingAs($this->member)
        ->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page->where('audience.mainTimezone', 'Australia/Sydney')
            ->has('audience.locations', 2)
            ->where('audience.locations.0.country', 'AU')
            ->where('canEdit', false));

    $this->actingAs($this->member)
        ->put(route('socialhq.plan.audience.update'), ['locations' => []])
        ->assertForbidden();

    expect($this->profile->fresh()->audience_locations)->toHaveCount(2);
});

test('without places the main zone is the viewer\'s own', function () {
    $this->actingAs($this->owner)
        ->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page->where('audience.mainTimezone', 'Asia/Kathmandu')
            ->where('audience.locations', [])
            ->where('audience.countries', fn ($countries) => collect($countries)->contains(fn ($country) => $country['code'] === 'US' && $country['timezone'] === 'America/New_York')));
});

test('audience places must be real, add up to 100% at most and not repeat', function (array $locations, string $field) {
    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.audience.update'), ['locations' => $locations])
        ->assertSessionHasErrors($field);

    expect($this->profile->fresh()->audience_locations)->toBeNull();
})->with([
    'unknown country' => [[['country' => 'XX', 'city' => null, 'timezone' => 'UTC', 'share' => 10]], 'locations.0.country'],
    'unknown time zone' => [[['country' => 'NP', 'city' => null, 'timezone' => 'Mars/Olympus', 'share' => 10]], 'locations.0.timezone'],
    'no share' => [[['country' => 'NP', 'city' => null, 'timezone' => 'Asia/Kathmandu', 'share' => 0]], 'locations.0.share'],
    'over 100%' => [[
        ['country' => 'NP', 'city' => null, 'timezone' => 'Asia/Kathmandu', 'share' => 60],
        ['country' => 'IN', 'city' => null, 'timezone' => 'Asia/Kolkata', 'share' => 41],
    ], 'locations'],
    'the same place twice' => [[
        ['country' => 'NP', 'city' => 'Pokhara', 'timezone' => 'Asia/Kathmandu', 'share' => 10],
        ['country' => 'NP', 'city' => 'pokhara', 'timezone' => 'Asia/Kathmandu', 'share' => 10],
    ], 'locations.1.city'],
    'more than 10 places' => [array_map(fn (int $index): array => ['country' => 'NP', 'city' => "City {$index}", 'timezone' => 'Asia/Kathmandu', 'share' => 1], range(1, 11)), 'locations'],
]);

test('the planner gets the audience and its main zone, and only the days asked for', function () {
    $this->profile->update(['audience_locations' => ($this->audience)()]);
    $this->plan->update(['generation_options' => ['from' => ($this->day)(7), 'to' => ($this->day)(13), 'old_calendars' => true, 'past_posts' => true, 'audience_events' => false]]);

    $context = PlannerContext::build($this->plan->fresh(), CarbonImmutable::now('UTC')->startOfDay(), 'Asia/Kathmandu');

    expect($context['range'])->toBe(['from' => ($this->day)(7), 'to' => ($this->day)(13)])
        ->and($context['days'])->toHaveCount(7)
        ->and($context['days'][0])->toStartWith(($this->day)(7))
        ->and($context['audience']['main_timezone'])->toBe('Australia/Sydney')
        ->and($context['audience']['locations'][0])->toBe(['country' => 'Australia', 'city' => 'Sydney', 'timezone' => 'Australia/Sydney', 'share_percent' => 50])
        ->and($context)->toHaveKeys(['past_posts', 'old_calendar']);
});

test('the planner leaves out old calendars and recent posts when they are switched off', function () {
    $import = CalendarImport::factory()->create(['workspace_id' => $this->workspace->id]);
    PastPost::factory()->create(['calendar_import_id' => $import->id, 'posted_on' => $this->month->subYear()->toDateString()]);
    AnalyticsPublication::factory()->create(['workspace_id' => $this->workspace->id, 'provider_published_at' => now()->subDays(3)]);
    $this->plan->update(['generation_options' => ['old_calendars' => false, 'past_posts' => false, 'audience_events' => false]]);

    $context = PlannerContext::build($this->plan->fresh(), CarbonImmutable::now('UTC')->startOfDay());

    expect($context)->not->toHaveKeys(['past_posts', 'old_calendar'])
        ->and($context['days'])->toHaveCount($this->month->daysInMonth)
        ->and($context['audience'])->toBe(['main_timezone' => 'UTC', 'locations' => []]);
});

test('events for the audience keep its countries\' and international ones', function () {
    $this->profile->update(['audience_locations' => ($this->audience)()]);
    $event = fn (string $key, ?string $country) => CalendarEvent::factory()->verified()->create([
        'account_id' => $this->workspace->account_id,
        'key' => $key,
        'country_code' => $country,
        'starts_on' => $this->month->addDays(10),
        'ends_on' => $this->month->addDays(10),
    ]);
    $event('dashain', 'NP');
    $event('australia-day', 'AU');
    $event('thanksgiving', 'US');
    $event('earth-day', null);

    $this->plan->update(['generation_options' => ['audience_events' => true]]);
    $filtered = PlannerContext::build($this->plan->fresh(), CarbonImmutable::now('UTC')->startOfDay());

    $this->plan->update(['generation_options' => ['audience_events' => false]]);
    $all = PlannerContext::build($this->plan->fresh(), CarbonImmutable::now('UTC')->startOfDay());

    expect(array_column($filtered['events'], 'key'))->toEqualCanonicalizing(['dashain', 'australia-day', 'earth-day'])
        ->and(array_column($all['events'], 'key'))->toEqualCanonicalizing(['dashain', 'australia-day', 'thanksgiving', 'earth-day']);
});

test('a run keeps to its days and the audience\'s events, gives items a time in the main zone and remembers the switches', function () {
    $this->profile->update(['audience_locations' => ($this->audience)()]);
    CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'key' => 'thanksgiving', 'country_code' => 'US', 'starts_on' => $this->month->addDays(9), 'ends_on' => $this->month->addDays(9)]);
    $outside = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(20), 'title' => 'Later suggestion']);
    $inside = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(8), 'title' => 'Earlier draft']);

    ContentPlanner::fake([[
        'summary' => 'A week of momos.',
        'items' => [
            ($this->suggestion)(),
            ($this->suggestion)(['date' => ($this->day)(7), 'title' => 'No usable time', 'suggested_time' => '25:00']),
            ($this->suggestion)(['date' => ($this->day)(20), 'title' => 'Outside the days']),
            ($this->suggestion)(['date' => ($this->day)(9), 'title' => 'Thanksgiving special', 'event_key' => 'thanksgiving']),
            ($this->suggestion)(['date' => ($this->day)(10), 'title' => 'Later suggestion']),
        ],
    ]]);

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), [
            'goals' => 'Momo week.',
            'from' => ($this->day)(5),
            'to' => ($this->day)(11),
            'old_calendars' => false,
            'past_posts' => true,
            'audience_events' => true,
        ])
        ->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $saved = $plan->items()->where('status', ItemStatus::Suggested)->orderBy('scheduled_on')->get()->keyBy('title');

    expect($saved->keys()->all())->toEqualCanonicalizing(['Momo folding time-lapse', 'No usable time', 'Later suggestion'])
        ->and($saved['Momo folding time-lapse']->suggested_time)->toBe('18:30')
        ->and($saved['Momo folding time-lapse']->suggested_timezone)->toBe('Australia/Sydney')
        ->and($saved['No usable time']->suggested_time)->toBeNull()
        ->and($saved['No usable time']->suggested_timezone)->toBeNull()
        ->and($saved['Later suggestion']->id)->toBe($outside->id)
        ->and($inside->fresh())->toBeNull()
        ->and(collect($plan->generation_rejections)->pluck('reason', 'title')->all())->toBe([
            'Outside the days' => 'date_outside_range',
            'Thanksgiving special' => 'unknown_event',
            'Later suggestion' => 'duplicate_title',
        ])
        ->and($plan->generation_options)->toEqual(['from' => ($this->day)(5), 'to' => ($this->day)(11), 'old_calendars' => false, 'past_posts' => true, 'audience_events' => true])
        ->and($this->profile->fresh()->planner_options)->toEqual(['old_calendars' => false, 'past_posts' => true, 'audience_events' => true]);

    ContentPlanner::assertPrompted(fn ($prompt) => $prompt->contains('Australia/Sydney') && ! $prompt->contains('thanksgiving') && ! $prompt->contains('"old_calendar"'));

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('generateDefaults', ['from' => ($this->day)(0), 'to' => $this->month->endOfMonth()->toDateString(), 'old_calendars' => false, 'past_posts' => true, 'audience_events' => true])
            ->where('audience.mainTimezone', 'Australia/Sydney')
            ->where('audience.countries.0.code', 'AU'));
});

test('a run\'s days must lie inside the month and in order', function (Closure $payload, string $field) {
    ContentPlanner::fake();

    $this->actingAs($this->owner)
        ->post(route('socialhq.plan.generate', $this->monthKey), $payload($this))
        ->assertSessionHasErrors($field);

    ContentPlanner::assertNeverPrompted();
})->with([
    'before the month' => [fn ($test) => ['from' => $test->month->subDay()->toDateString(), 'to' => ($test->day)(3)], 'from'],
    'after the month' => [fn ($test) => ['from' => ($test->day)(3), 'to' => $test->month->addMonthNoOverflow()->toDateString()], 'to'],
    'last day first' => [fn ($test) => ['from' => ($test->day)(9), 'to' => ($test->day)(3)], 'to'],
    'only one end' => [fn ($test) => ['from' => ($test->day)(3)], 'to'],
]);

test('a person sets or clears an item\'s time, written in the main audience zone', function () {
    $this->profile->update(['audience_locations' => ($this->audience)()]);
    $item = PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(4)]);
    $payload = fn (array $overrides = []): array => [
        'scheduled_on' => ($this->day)(4),
        'social_account_id' => $this->instagram->id,
        'content_type' => ContentType::InstagramReel->value,
        'creative' => 'video',
        'title' => 'Behind the momo counter',
        ...$overrides,
    ];

    $this->actingAs($this->member)->put(route('socialhq.plan.items.update', $item->id), $payload(['suggested_time' => '9:5']))->assertSessionHasErrors('suggested_time');
    $this->actingAs($this->member)->put(route('socialhq.plan.items.update', $item->id), $payload(['suggested_time' => '09:00']))->assertSessionHasNoErrors();

    expect($item->fresh()->only('suggested_time', 'suggested_timezone'))->toBe(['suggested_time' => '09:00', 'suggested_timezone' => 'Australia/Sydney']);

    $instant = CarbonImmutable::createFromFormat('!Y-m-d H:i', ($this->day)(4).' 09:00', 'Australia/Sydney')->utc();

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('items.0.suggested_time', '09:00')
            ->where('items.0.suggested_timezone', 'Australia/Sydney')
            ->where('items.0.suggested_at', $instant->toIso8601ZuluString()));

    $this->actingAs($this->member)->put(route('socialhq.plan.items.update', $item->id), $payload(['suggested_time' => null]))->assertSessionHasNoErrors();

    expect($item->fresh()->only('suggested_time', 'suggested_timezone'))->toBe(['suggested_time' => null, 'suggested_timezone' => null]);
});

test('create post hands the composer the item\'s time as a wall clock in the user\'s zone', function () {
    $item = PlanItem::factory()->for($this->plan, 'plan')->create([
        'social_account_id' => $this->instagram->id,
        'scheduled_on' => ($this->day)(4),
        'creative' => CreativeKind::None,
        'copy_status' => CopyStatus::Approved,
        'suggested_time' => '09:00',
        'suggested_timezone' => 'Australia/Sydney',
    ]);
    ItemVersion::factory()->for($item, 'item')->create();
    $expected = CarbonImmutable::createFromFormat('!Y-m-d H:i', ($this->day)(4).' 09:00', 'Australia/Sydney')->setTimezone('Asia/Kathmandu')->format('Y-m-d\TH:i');

    $this->actingAs($this->member)
        ->postJson(route('socialhq.plan.items.post.store', $item->id))
        ->assertOk()
        ->assertJsonPath('scheduled_at', $expected);

    $item->update(['suggested_time' => null, 'suggested_timezone' => null]);

    $this->actingAs($this->member)
        ->postJson(route('socialhq.plan.items.post.store', $item->id))
        ->assertOk()
        ->assertJsonPath('scheduled_at', null);
});
