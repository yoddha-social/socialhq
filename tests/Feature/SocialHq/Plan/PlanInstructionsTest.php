<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\Ai\ContentPlanner;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\MustHave;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Exceptions\AiException;

/*
 * Owner feedback 2026-11-26 on the Generate dialog: the focus box alone
 * ("nothing on saturdays / contents more about the brands") wasn't followed.
 * "Instructions for the AI" (approved mockup generate-plan.html) adds the
 * plan's strategy, must-have posts and days off, and the app enforces the
 * last two in code, as it does the planner's other hard rules.
 */

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.structured' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);

    $this->owner = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    // AI runs for a company a platform admin allowed Social HQ's AI keys.
    Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->member = workspaceMember($this->workspace, 'approval', ['timezone' => 'UTC']);
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->monthKey = $this->month->format('Y-m');
    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->profile = BrandProfile::query()->create([
        'workspace_id' => $this->workspace->id,
        'about' => 'A Kathmandu payroll software company.',
        'cadence' => [$this->instagram->id => 3],
    ]);
    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);
    // A whole calendar week inside the month, from the requester's first day of the week (Monday).
    $this->monday = $this->month->addDays(7)->startOfWeek(CarbonImmutable::MONDAY);
    $this->weekDay = fn (int $offset): string => $this->monday->addDays($offset)->toDateString();
    $this->week = fn (array $values = []): array => ['from' => ($this->weekDay)(0), 'to' => ($this->weekDay)(6), ...$values];

    $this->mustHave = fn (string $date, ?string $channel = null, ?ContentType $type = null, string $focus = 'Month-end payroll closing checklist'): array => [
        'date' => $date,
        'channel' => $channel,
        'content_type' => $type?->value,
        'focus' => $focus,
    ];

    $this->suggestion = fn (array $overrides = []): array => [
        'date' => ($this->weekDay)(1),
        'channel' => $this->instagram->id,
        'content_type' => ContentType::InstagramReel->value,
        'creative' => 'video',
        'pillar' => '',
        'title' => 'Payslip in 30 seconds',
        'objective' => 'Show how fast a payslip goes out.',
        'angle' => 'Screen recording of one run.',
        'event_key' => '',
        'suggested_time' => '',
        'must_have' => '',
        ...$overrides,
    ];

    $this->generate = fn (array $payload): mixed => $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey), $payload);
});

test('a must-have needs a coming day inside the days to plan, a focus, an active channel and a format it takes', function (Closure $payload, string $field, string $message) {
    ContentPlanner::fake();
    $this->profile->update(['cadence' => [$this->instagram->id => 3, $this->linkedin->id => 0]]);

    ($this->generate)($payload($this))->assertSessionHasErrors([$field => $message]);

    ContentPlanner::assertNeverPrompted();
    expect($this->plan->fresh()->generation_options)->toBeNull();
})->with([
    'before the days to plan' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)($test->monday->subDay()->toDateString())]]), 'must_haves.0.date', 'Pick a day inside Days to plan, or remove this must-have.'],
    'after the days to plan' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)($test->monday->addDays(7)->toDateString())]]), 'must_haves.0.date', 'Pick a day inside Days to plan, or remove this must-have.'],
    'no day' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)('')]]), 'must_haves.0.date', 'Pick a day inside Days to plan, or remove this must-have.'],
    'no focus' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)(($test->weekDay)(2), focus: '')]]), 'must_haves.0.focus', 'Write what this post must be about.'],
    'a focus over 300 characters' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)(($test->weekDay)(2), focus: str_repeat('a', MustHave::MAX_FOCUS_LENGTH + 1))]]), 'must_haves.0.focus', 'Keep what it must be about under 300 characters.'],
    'more than ten' => [fn ($test) => ($test->week)(['must_haves' => array_fill(0, MustHave::MAX + 1, ($test->mustHave)(($test->weekDay)(2)))]), 'must_haves', 'Add up to 10 must-haves.'],
    'a channel set to 0 posts a week' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)(($test->weekDay)(2), $test->linkedin->id)]]), 'must_haves.0.channel', 'Pick Any channel, or one of the channels in the list.'],
    'a format the channel doesn\'t take' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)(($test->weekDay)(2), $test->instagram->id, ContentType::LinkedInPost)]]), 'must_haves.0.content_type', 'Pick Any format, or one this channel offers.'],
    'a format no active channel takes' => [fn ($test) => ($test->week)(['must_haves' => [($test->mustHave)(($test->weekDay)(2), null, ContentType::LinkedInPost)]]), 'must_haves.0.content_type', 'Pick Any format, or one this channel offers.'],
    'a day off outside the week' => [fn ($test) => ($test->week)(['days_off' => [7]]), 'days_off.0', 'Pick your days off from the days of the week shown.'],
    'the same day off twice' => [fn ($test) => ($test->week)(['days_off' => [6, 6]]), 'days_off.1', 'Pick your days off from the days of the week shown.'],
]);

test('a must-have on a day already gone is refused', function () {
    $this->travelTo(CarbonImmutable::now('UTC')->startOfMonth()->addDays(14)->setTime(12, 0));
    $month = CarbonImmutable::now('UTC')->startOfMonth();
    ContentPlanner::fake();

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $month->format('Y-m')), [
            'from' => $month->toDateString(),
            'to' => $month->endOfMonth()->toDateString(),
            'must_haves' => [($this->mustHave)(CarbonImmutable::now('UTC')->subDay()->toDateString())],
        ])
        ->assertSessionHasErrors(['must_haves.0.date' => 'This day has passed. Pick a coming day, or remove this must-have.']);

    ContentPlanner::assertNeverPrompted();
});

test('a suggestion on a day off moves to the nearest free day of its week, and the AI is told the days off', function () {
    $prompts = fakePlannerWeeks([($this->suggestion)(['date' => ($this->weekDay)(2), 'title' => 'Midweek payroll tip'])]);

    // Wednesdays off.
    ($this->generate)(($this->week)(['days_off' => [CarbonImmutable::WEDNESDAY]]))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $first = $prompts[0];

    expect($plan->items()->sole()->scheduled_on->toDateString())->toBe(($this->weekDay)(1))
        ->and($plan->generation_rejections)->toBe([])
        ->and($plan->generation_weeks[0]['repaired'])->toBeGreaterThanOrEqual(1)
        ->and($plan->generation_options['days_off'])->toBe([CarbonImmutable::WEDNESDAY])
        ->and($first['days_off'])->toBe(['Wed'])
        ->and(collect($first['channels'])->every(fn (array $channel): bool => ! in_array(($this->weekDay)(2), $channel['free_days'], true)))->toBeTrue();
});

test('a suggestion with no free day of its week left is refused as on a day off', function () {
    fakePlannerWeeks([($this->suggestion)(['date' => ($this->weekDay)(2)])]);

    ($this->generate)(($this->week)(['days_off' => range(0, 6)]))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();

    expect($plan->items()->count())->toBe(0)
        ->and(collect($plan->generation_rejections)->pluck('reason')->all())->toBe(['plan_day_off'])
        ->and(__('socialhq_plan.generation.reasons.plan_day_off'))->toBe('On one of your days off');
});

test('the AI\'s item for a must-have goes on its day, channel and format, and the AI is told what is left around it', function () {
    $prompts = fakePlannerWeeks([
        // It names the must-have, but on another day, channel and format.
        ($this->suggestion)(['date' => ($this->weekDay)(1), 'title' => 'SSF deadline countdown', 'must_have' => 'm1']),
    ]);

    ($this->generate)(($this->week)(['must_haves' => [($this->mustHave)(($this->weekDay)(3), $this->linkedin->id, ContentType::LinkedInPost, 'SSF deadline reminder')]]))
        ->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $item = $plan->items()->sole();
    $first = $prompts[0];
    $linkedin = collect($first['channels'])->firstWhere('key', $this->linkedin->id);

    expect($item->title)->toBe('SSF deadline countdown')
        ->and($item->scheduled_on->toDateString())->toBe(($this->weekDay)(3))
        ->and($item->social_account_id)->toBe($this->linkedin->id)
        ->and($item->content_type)->toBe(ContentType::LinkedInPost)
        ->and($item->must_have)->toBe(MustHave::listFrom($plan->generation_options['must_haves'], $this->month)[0]->key())
        ->and($plan->generation_weeks[0])->toMatchArray(['must_haves_added' => 0, 'filled' => false])
        ->and($plan->generation_weeks[0]['repaired'])->toBeGreaterThanOrEqual(3)
        // The must-have was counted before the AI was asked: one of LinkedIn's two posts, its day taken.
        ->and($first['must_haves'])->toBe([['id' => 'M1', 'date' => ($this->weekDay)(3), 'channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'focus' => 'SSF deadline reminder']])
        ->and($linkedin['posts_to_plan'])->toBe(1)
        ->and($linkedin['free_days'])->not->toContain(($this->weekDay)(3));
});

test('a must-have the AI missed is added by the app: for any channel on the one with the most room that week, in its usual format', function () {
    // Instagram has 2 of its 3 posts that week; LinkedIn none of its 2.
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->weekDay)(0), 'title' => 'Planned one']);
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->weekDay)(1), 'title' => 'Planned two']);
    fakePlannerWeeks([
        // Names a must-have that isn't there: kept as an ordinary suggestion.
        ($this->suggestion)(['date' => ($this->weekDay)(5), 'channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'title' => 'Team lunch', 'must_have' => 'M7']),
    ]);

    ($this->generate)(($this->week)(['must_haves' => [
        ($this->mustHave)(($this->weekDay)(2), focus: 'Month-end payroll closing checklist'),
        ($this->mustHave)(($this->weekDay)(4), null, ContentType::InstagramReel, 'How a payslip reaches every phone'),
    ]]))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $items = $plan->items()->where('status', ItemStatus::Suggested)->get()->keyBy('title');
    $checklist = $items['Month-end payroll closing checklist'];
    $reel = $items['How a payslip reaches every phone'];

    expect($items->keys()->sort()->values()->all())->toBe(['How a payslip reaches every phone', 'Month-end payroll closing checklist', 'Team lunch'])
        // Any channel and any format: LinkedIn has the most room, in its usual format.
        ->and([$checklist->scheduled_on->toDateString(), $checklist->social_account_id, $checklist->content_type, $checklist->creative])
        ->toBe([($this->weekDay)(2), $this->linkedin->id, ContentType::LinkedInPost, CreativeKind::Image])
        ->and($checklist->objective)->toBe('Month-end payroll closing checklist')
        ->and($checklist->must_have)->not->toBeNull()
        // Any channel that takes reels: only Instagram does.
        ->and([$reel->scheduled_on->toDateString(), $reel->social_account_id, $reel->content_type])->toBe([($this->weekDay)(4), $this->instagram->id, ContentType::InstagramReel])
        ->and($reel->must_have)->not->toBeNull()
        ->and($items['Team lunch']->must_have)->toBeNull()
        ->and($plan->generation_weeks[0])->toMatchArray(['must_haves_added' => 2, 'added' => 3]);

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.must_haves_added', 2)
            ->where('items', fn ($all): bool => collect($all)->where('must_have', true)->pluck('title')->sort()->values()->all() === ['How a payslip reaches every phone', 'Month-end payroll closing checklist']));

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.items.show', $checklist->id))
        ->assertInertia(fn ($page) => $page->where('item.must_have', true));
});

test('a week the must-haves fill gets no AI call, and its result says so', function () {
    ContentPlanner::fake();
    $this->profile->update(['cadence' => [$this->instagram->id => 1, $this->linkedin->id => 0]]);

    ($this->generate)(($this->week)(['must_haves' => [($this->mustHave)(($this->weekDay)(2), $this->instagram->id)]]))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $item = $plan->items()->sole();

    ContentPlanner::assertNeverPrompted();
    expect([$item->scheduled_on->toDateString(), $item->social_account_id, $item->content_type])->toBe([($this->weekDay)(2), $this->instagram->id, ContentType::InstagramFeed])
        ->and($plan->generation_weeks[0])->toMatchArray(['status' => 'done', 'filled' => true, 'calls' => 0, 'must_haves_added' => 1]);

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.weeks.0.filled', true)->where('plan.generation.status', 'idle'));
});

test('a must-have on a day off still goes on its day', function () {
    fakePlannerWeeks([]);

    ($this->generate)(($this->week)([
        'days_off' => [CarbonImmutable::SATURDAY],
        'must_haves' => [($this->mustHave)(($this->weekDay)(5), $this->instagram->id, ContentType::InstagramReel)],
    ]))->assertSessionHasNoErrors();

    $item = $this->plan->fresh()->items()->sole();

    expect([$item->scheduled_on->toDateString(), $item->content_type, $item->creative])->toBe([($this->weekDay)(5), ContentType::InstagramReel, CreativeKind::Video]);
});

test('a must-have an item already covers is not added again', function () {
    $mustHave = ($this->mustHave)(($this->weekDay)(2), $this->instagram->id);
    PlanItem::factory()->for($this->plan, 'plan')->create([
        'social_account_id' => $this->instagram->id,
        'scheduled_on' => ($this->weekDay)(2),
        'title' => 'Accepted last time',
        'must_have' => MustHave::listFrom([$mustHave], $this->month)[0]->key(),
    ]);
    $prompts = fakePlannerWeeks([]);

    ($this->generate)(($this->week)(['must_haves' => [$mustHave]]))->assertSessionHasNoErrors();

    expect($this->plan->fresh()->items()->pluck('title')->all())->toBe(['Accepted last time'])
        ->and($prompts[0]['must_haves'])->toBe([]);
});

test('a week the AI couldn\'t draft still gets its must-haves', function () {
    Exceptions::fake();
    ContentPlanner::fake(fn () => throw new AiException('Provider overloaded'));

    ($this->generate)(($this->week)(['must_haves' => [($this->mustHave)(($this->weekDay)(2), $this->instagram->id)]]))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();

    expect($plan->items()->sole()->must_have)->not->toBeNull()
        ->and($plan->generation_weeks[0])->toMatchArray(['status' => 'failed', 'error' => 'ai_failed', 'calls' => 2, 'must_haves_added' => 1]);
});

test('kept instructions open next month\'s dialog; must-haves stay with their month; turning keep off clears them', function () {
    fakePlannerWeeks([]);
    $mustHave = ($this->mustHave)(($this->weekDay)(2), $this->instagram->id, ContentType::InstagramReel);
    $nextMonth = $this->month->addMonthNoOverflow()->format('Y-m');

    ($this->generate)(($this->week)(['goals' => 'Push the brand, not insights.', 'days_off' => [CarbonImmutable::SATURDAY], 'must_haves' => [$mustHave]]))
        ->assertSessionHasNoErrors();

    expect($this->profile->fresh()->planner_options)->toMatchArray(['strategy' => 'Push the brand, not insights.', 'days_off' => [CarbonImmutable::SATURDAY]])
        ->and($this->plan->fresh()->generation_options['must_haves'])->toBe([$mustHave]);

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $nextMonth))
        ->assertInertia(fn ($page) => $page->where('generateDefaults.strategy', 'Push the brand, not insights.')
            ->where('generateDefaults.days_off', [CarbonImmutable::SATURDAY])
            ->where('generateDefaults.must_haves', []));

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('generateDefaults.must_haves', [$mustHave])
            ->where('generateDefaults.strategy', 'Push the brand, not insights.'));

    ($this->generate)(($this->week)(['goals' => 'Only this month.', 'days_off' => [CarbonImmutable::SUNDAY], 'keep_instructions' => false]))
        ->assertSessionHasNoErrors();

    expect($this->profile->fresh()->planner_options)->not->toHaveKeys(['strategy', 'days_off']);

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $nextMonth))
        ->assertInertia(fn ($page) => $page->where('generateDefaults.strategy', '')->where('generateDefaults.days_off', []));

    // This month's own run still opens with its days off.
    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('generateDefaults.days_off', [CarbonImmutable::SUNDAY])
            ->where('generateDefaults.strategy', 'Only this month.'));
});

test('the prompt presents the strategy as the plan\'s, for every week, and asks for the must-have of each item', function () {
    $instructions = (new ContentPlanner(Locale::DEFAULT))->instructions();

    expect($instructions)->toContain('"strategy": the team\'s strategy for this plan')
        ->and($instructions)->toContain('Plan every week you draft around it')
        ->and($instructions)->toContain('Cover every must-have with exactly one item whose "must_have" is its "id"')
        ->and($instructions)->toContain('"days_off"');
});
