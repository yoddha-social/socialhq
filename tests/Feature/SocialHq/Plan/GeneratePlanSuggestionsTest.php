<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\User\Locale;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\Ai\ContentPlanner;
use App\SocialHq\Plan\Ai\GeneratePlanSuggestions;
use App\SocialHq\Plan\Ai\PlannerContext;
use App\SocialHq\Plan\Ai\SuggestionChecks;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\GenerationStatus;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanStatus;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.structured' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    // AI runs for a company a platform admin allowed Social HQ's AI keys.
    Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
    $this->member = workspaceMember($this->workspace, 'approval');
    $this->month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    $this->monthKey = $this->month->format('Y-m');
    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    BrandProfile::query()->create([
        'workspace_id' => $this->workspace->id,
        'about' => 'A Kathmandu momo chain.',
        'pillars' => ['Menu', 'Team'],
        'cadence' => [$this->instagram->id => 3],
    ]);
    $this->plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $this->month->toDateString()]);
    $this->day = fn (int $offset): string => $this->month->addDays($offset)->toDateString();
    // A whole calendar week inside the month, from the requester's first day of the week (Monday).
    $this->monday = $this->month->addDays(7)->startOfWeek(CarbonImmutable::MONDAY);
    $this->weekDay = fn (int $offset): string => $this->monday->addDays($offset)->toDateString();

    $this->suggestion = fn (array $overrides = []): array => [
        'date' => ($this->day)(6),
        'channel' => $this->instagram->id,
        'content_type' => ContentType::InstagramReel->value,
        'creative' => 'video',
        'pillar' => 'Menu',
        'title' => 'Momo folding time-lapse',
        'objective' => 'Show the handmade process.',
        'angle' => 'Speed-up of the morning prep.',
        'event_key' => null,
        ...$overrides,
    ];
});

test('a run saves the items that pass as suggestions and reports the others with their reasons', function () {
    $planned = PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->weekDay)(0), 'title' => 'Kept']);
    $dropped = PlanItem::factory()->for($this->plan, 'plan')->create(['status' => ItemStatus::Dropped]);
    $oldSuggestion = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['scheduled_on' => ($this->weekDay)(2)]);
    $festival = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'key' => 'dashain', 'starts_on' => $this->monday->addDays(4), 'ends_on' => $this->monday->addDays(5)]);
    CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'key' => 'tihar', 'starts_on' => $this->monday->addDays(5), 'ends_on' => $this->monday->addDays(6)]);

    fakePlannerWeeks([
        ($this->suggestion)(['date' => ($this->weekDay)(1)]),
        ($this->suggestion)(['date' => ($this->weekDay)(2), 'channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'event_key' => 'dashain', 'pillar' => 'Memes', 'title' => 'Dashain greetings']),
        ($this->suggestion)(['date' => $this->month->addMonthNoOverflow()->toDateString(), 'title' => 'Too late']),
        // Nothing points at one of the brand's channels: no key, name or network of theirs, and no X channel.
        ($this->suggestion)(['date' => ($this->weekDay)(3), 'channel' => 'not-a-channel', 'content_type' => ContentType::XPost->value, 'title' => 'Wrong channel']),
        ($this->suggestion)(['date' => ($this->weekDay)(4), 'event_key' => 'tihar', 'title' => 'Unconfirmed festival']),
        ($this->suggestion)(['date' => ($this->weekDay)(4), 'event_key' => 'made-up-day', 'title' => 'Invented day']),
    ], summary: 'A month about the menu.');

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), ['goals' => 'Lean on Dashain.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $suggestions = $plan->items()->where('status', ItemStatus::Suggested)->orderBy('scheduled_on')->get();

    expect($plan->generation_status)->toBe(GenerationStatus::Idle)
        ->and($plan->goals)->toBe('Lean on Dashain.')
        ->and($plan->generation_summary)->toStartWith('A month about the menu.')
        ->and($plan->generated_at)->not->toBeNull()
        ->and($suggestions->pluck('title')->all())->toBe(['Momo folding time-lapse', 'Dashain greetings'])
        ->and($suggestions[0]->source)->toBe(ItemSource::Ai)
        ->and($suggestions[0]->pillar)->toBe('Menu')
        ->and($suggestions[0]->content_type)->toBe(ContentType::InstagramReel)
        ->and($suggestions[1]->event_id)->toBe($festival->id)
        ->and($suggestions[1]->pillar)->toBeNull()
        ->and($suggestions[1]->platform)->toBe('linkedin')
        ->and($oldSuggestion->fresh())->toBeNull()
        ->and($planned->fresh()->title)->toBe('Kept')
        ->and($dropped->fresh()->status)->toBe(ItemStatus::Dropped)
        ->and(collect($plan->generation_rejections)->pluck('reason', 'title')->all())->toBe([
            'Too late' => 'date_outside_month',
            'Wrong channel' => 'unknown_channel',
            'Unconfirmed festival' => 'unconfirmed_event',
            'Invented day' => 'unknown_event',
        ]);

    ContentPlanner::assertPrompted(fn ($prompt) => $prompt->contains('Lean on Dashain.') && $prompt->contains('dashain') && $prompt->contains('A Kathmandu momo chain.'));
});

test('new suggestions send an approved plan back for review', function () {
    $this->plan->update(['status' => PlanStatus::Approved, 'approved_at' => now()]);
    fakePlannerWeeks([($this->suggestion)()]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    expect($this->plan->fresh()->status)->toBe(PlanStatus::InReview);
});

test('an AI error leaves the plan failed with a message and its items untouched', function () {
    $suggestion = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create();
    ContentPlanner::fake(fn () => throw new AiException('Provider overloaded: secret-key-123'));

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertRedirect();

    expect($this->plan->fresh()->generation_status)->toBe(GenerationStatus::Failed)
        ->and($this->plan->fresh()->generation_error)->toBe('ai_failed')
        ->and($suggestion->fresh())->not->toBeNull();

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.status', 'failed')
            ->where('plan.generation.error', __('socialhq_plan.generation.errors.ai_failed')));
});

test('one run per plan at a time, unless the running one went stale', function () {
    $this->plan->update(['generation_status' => GenerationStatus::Running, 'generation_started_at' => now()->subMinutes(2)]);
    fakePlannerWeeks([], summary: 'Fresh.');

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertStatus(Response::HTTP_CONFLICT);
    ContentPlanner::assertNeverPrompted();

    $this->plan->update(['generation_started_at' => now()->subMinutes(ContentPlan::STALE_MINUTES + 1)]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.status', 'failed'));

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();
    expect($this->plan->fresh()->generation_status)->toBe(GenerationStatus::Idle)
        ->and($this->plan->fresh()->generation_summary)->toStartWith('Fresh.');
});

test('without an approved AI provider the button is hidden and a run is refused', function () {
    config(['ai.providers.anthropic.key' => null]);
    ContentPlanner::fake();

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('abilities.canGenerate', false)->where('ai.available', false));

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasErrors('generate');
    ContentPlanner::assertNeverPrompted();
});

test('someone outside the workspace cannot start a run', function () {
    ContentPlanner::fake();

    $this->actingAs(workspaceOutsider($this->workspace))->post(route('socialhq.plan.generate', $this->monthKey))->assertForbidden();

    ContentPlanner::assertNeverPrompted();
});

test('a person can start 5 runs an hour', function () {
    ContentPlanner::fake(fn () => ['summary' => 'Again.', 'items' => []]);

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();
    }

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
});

test('the planner context holds the month, brand, channels, events, planned items and past posts', function () {
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(1), 'title' => 'Already planned']);
    PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['title' => 'Only a suggestion']);
    CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'key' => 'tihar', 'starts_on' => $this->month->addDays(20), 'ends_on' => $this->month->addDays(21)]);
    $this->plan->update(['goals' => 'More reels.']);

    foreach (range(1, 64) as $rank) {
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $this->workspace->id,
            'provider_published_at' => now()->subDays(min($rank, 80)),
            'excerpt' => str_repeat("Post {$rank} ", 40),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'engagement_count' => $rank * 10, 'reach_count' => $rank * 100]);
    }
    AnalyticsPublication::factory()->create(['workspace_id' => $this->workspace->id, 'provider_published_at' => now()->subDays(120)]);
    AnalyticsPublication::factory()->create(['provider_published_at' => now()->subDay()]);

    $today = CarbonImmutable::now('UTC')->startOfDay();
    $context = PlannerContext::build($this->plan->fresh(), $today);

    expect($context['month'])->toBe($this->monthKey)
        ->and($context['today'])->toBe($today->toDateString())
        ->and($context['days'])->toHaveCount($this->month->daysInMonth)
        ->and($context['days'][0])->toBe($this->month->format('Y-m-d D'))
        ->and($context['goals'])->toBe('More reels.')
        ->and($context['brand'])->toContain('A Kathmandu momo chain.')
        ->and($context['pillars'])->toBe(['Menu', 'Team'])
        ->and($context['channels'])->toHaveCount(2)
        ->and($context['channels'][0])->toMatchArray(['key' => $this->instagram->id, 'platform' => 'instagram', 'posts_per_week' => 3])
        // What is still open is worked out in code: a month of posts per week, less the planned item, on free days only.
        ->and($context['channels'][0]['posts_to_plan'])->toBeBetween(11, 14)
        ->and($context['channels'][0]['free_days'])->not->toContain(($this->day)(1))
        ->and($context['channels'][1]['posts_to_plan'])->toBeBetween(8, 10)
        ->and($context['channels'][0]['content_types'])->toContain(ContentType::InstagramReel->value)
        ->and($context['channels'][1]['posts_per_week'])->toBeNull()
        ->and($context['events'])->toBe([['key' => 'tihar', 'name' => $context['events'][0]['name'], 'category' => 'festival', 'starts_on' => ($this->day)(20), 'ends_on' => ($this->day)(21), 'confirmed' => false, 'countries' => []]])
        ->and(collect($context['already_planned'])->map(fn (array $item): array => Arr::only($item, ['title', 'date', 'channel']))->all())->toBe([['title' => 'Already planned', 'date' => ($this->day)(1), 'channel' => $this->instagram->id]])
        ->and($context['already_planned'][0])->toHaveKey('pillar')
        ->and($context['past_posts'])->toHaveCount(PlannerContext::PAST_POSTS)
        ->and($context['past_posts'][0]['engagement'])->toBe(640)
        ->and($context['past_posts'][30]['engagement'])->toBe(10)
        ->and(mb_strlen($context['past_posts'][0]['excerpt']))->toBeLessThanOrEqual(PlannerContext::EXCERPT_LENGTH);
});

test('a run that ends after the plan was approved sends it back for review', function () {
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(1)]);
    // The plan is approved while the AI works on the first week.
    fakePlannerWeeks([($this->suggestion)()], whileAsked: fn (array $prompt, int $call) => $call === 1
        ? $this->plan->fresh()->forceFill(['status' => PlanStatus::Approved, 'approved_at' => now(), 'approved_by' => $this->owner->id])->save()
        : null);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    expect($this->plan->fresh()->status)->toBe(PlanStatus::InReview)
        ->and($this->plan->fresh()->approved_at)->toBeNull()
        ->and($this->plan->items()->where('status', ItemStatus::Suggested)->count())->toBe(1);
});

test('approving is refused while an AI draft is running', function () {
    PlanItem::factory()->for($this->plan, 'plan')->create();
    $this->plan->update(['generation_status' => GenerationStatus::Running, 'generation_started_at' => now(), 'generation_run_id' => (string) Str::uuid7()]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.approve', $this->monthKey))->assertSessionHasErrors('plan');

    expect($this->plan->fresh()->status)->toBe(PlanStatus::Draft);
});

test('a job whose run was replaced by a newer one writes nothing', function () {
    $newerRun = (string) Str::uuid7();
    ContentPlanner::fake(function () use ($newerRun) {
        $this->plan->fresh()->forceFill(['generation_run_id' => $newerRun, 'generation_started_at' => now()])->save();

        return ['summary' => 'Old run.', 'items' => [($this->suggestion)()]];
    });

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();

    expect($plan->items()->count())->toBe(0)
        ->and($plan->generation_status)->toBe(GenerationStatus::Running)
        ->and($plan->generation_run_id)->toBe($newerRun)
        ->and($plan->generation_summary)->toBeNull();
});

test('an item planned while the AI worked still holds its channel\'s day: the suggestion moves to the nearest free one', function () {
    fakePlannerWeeks([($this->suggestion)(['date' => ($this->weekDay)(2)])], whileAsked: function (array $prompt) {
        if (data_get($prompt, 'range.from') <= ($this->weekDay)(2) && data_get($prompt, 'range.to') >= ($this->weekDay)(2) && data_get($prompt, 'retry') === null) {
            PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->weekDay)(2), 'title' => 'Added meanwhile']);
        }
    });

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    // Monday and Wednesday are as near; the earlier one wins.
    expect($this->plan->items()->where('status', ItemStatus::Suggested)->sole()->scheduled_on->toDateString())->toBe(($this->weekDay)(1))
        ->and($this->plan->fresh()->generation_rejections)->toBe([]);
});

test('in the current month the AI is told today and days before it are refused', function () {
    $this->travelTo(CarbonImmutable::now('UTC')->startOfMonth()->addDays(14)->setTime(12, 0));
    $month = CarbonImmutable::now('UTC')->startOfMonth();
    $today = CarbonImmutable::now('UTC')->toDateString();
    $this->member->update(['timezone' => 'UTC']);
    ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $month->toDateString()]);
    // The run's calls start today; the day before goes to the first one anyway.
    fakePlannerWeeks([
        ($this->suggestion)(['date' => $month->addDays(13)->toDateString(), 'title' => 'Yesterday', 'week' => 0]),
        ($this->suggestion)(['date' => $today, 'title' => 'Today']),
    ]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $month->format('Y-m')))->assertSessionHasNoErrors();

    $plan = ContentPlan::query()->where('workspace_id', $this->workspace->id)->whereDate('month', $month->toDateString())->sole();

    expect($plan->items()->pluck('title')->all())->toBe(['Today'])
        ->and(collect($plan->generation_rejections)->pluck('reason', 'title')->all())->toBe(['Yesterday' => 'date_in_past']);

    ContentPlanner::assertPrompted(fn ($prompt) => $prompt->contains("\"today\":\"{$today}\""));
});

test('a posting time already gone today is dropped, not the item, and the AI is told the time now', function () {
    $this->travelTo(CarbonImmutable::now('UTC')->startOfMonth()->addDays(14)->setTime(12, 0));
    $month = CarbonImmutable::now('UTC')->startOfMonth();
    $today = CarbonImmutable::now('UTC');
    // No audience places: the main zone is the planner's own.
    $this->member->update(['timezone' => 'UTC']);
    ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $month->toDateString()]);
    fakePlannerWeeks([
        ($this->suggestion)(['date' => $today->toDateString(), 'title' => 'This morning', 'suggested_time' => '09:00']),
        ($this->suggestion)(['date' => $today->toDateString(), 'channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'title' => 'This evening', 'suggested_time' => '18:30']),
        ($this->suggestion)(['date' => $today->addDay()->toDateString(), 'title' => 'Tomorrow morning', 'suggested_time' => '09:00']),
    ]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $month->format('Y-m')))->assertSessionHasNoErrors();

    $items = PlanItem::query()->whereRelation('plan', 'month', $month->toDateString())->get()->keyBy('title');

    expect($items->keys()->sort()->values()->all())->toBe(['This evening', 'This morning', 'Tomorrow morning'])
        ->and($items['This morning']->only('suggested_time', 'suggested_timezone'))->toBe(['suggested_time' => null, 'suggested_timezone' => null])
        ->and($items['This evening']->only('suggested_time', 'suggested_timezone'))->toBe(['suggested_time' => '18:30', 'suggested_timezone' => 'UTC'])
        ->and($items['Tomorrow morning']->suggested_time)->toBe('09:00');

    ContentPlanner::assertPrompted(fn ($prompt) => $prompt->contains("\"now\":\"{$today->format('Y-m-d')} 12:00\""));
});

test('the job repairs what it can be sure of: the channel, the format, what to make, the event, the pillar and the day', function () {
    BrandProfile::query()->where('workspace_id', $this->workspace->id)->update(['cadence' => [$this->instagram->id => 5]]);
    // Saturdays are switched off in the Instagram channel's posting times.
    $this->instagram->update(['posting_schedule' => PostingSchedule::empty()->withDayEnabled(CarbonImmutable::SATURDAY, false)]);
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->weekDay)(0), 'title' => 'Momo Monday']);
    $dashain = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'key' => 'dashain', 'name' => 'Bada Dashain', 'starts_on' => $this->monday->addDays(4), 'ends_on' => $this->monday->addDays(5)]);
    $typo = Str::substrReplace($this->instagram->id, $this->instagram->id[10] === 'a' ? 'b' : 'a', 10, 1);

    fakePlannerWeeks([
        ($this->suggestion)(['date' => ($this->weekDay)(1), 'channel' => $typo, 'title' => 'Typo in the key']),
        ($this->suggestion)(['date' => ($this->weekDay)(1), 'channel' => 'linkedin', 'content_type' => ContentType::InstagramFeed->value, 'creative' => 'carousel', 'title' => 'Network instead of key']),
        ($this->suggestion)(['date' => ($this->weekDay)(2), 'content_type' => ContentType::FacebookReel->value, 'creative' => 'image', 'title' => 'Reel of another network']),
        ($this->suggestion)(['date' => ($this->weekDay)(0), 'title' => 'Same day as Momo Monday']),
        ($this->suggestion)(['date' => ($this->weekDay)(0), 'channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'event_key' => 'Bada Dashain', 'pillar' => 'menu', 'title' => 'Dashain by its name']),
        ($this->suggestion)(['date' => ($this->weekDay)(5), 'title' => 'On a day off']),
    ]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $items = $plan->items()->where('status', ItemStatus::Suggested)->get()->keyBy('title');
    $shape = fn (string $title): array => [
        $items[$title]->social_account_id,
        $items[$title]->content_type,
        $items[$title]->creative,
        $items[$title]->scheduled_on->toDateString(),
    ];

    expect($plan->generation_rejections)->toBe([])
        ->and($shape('Typo in the key'))->toBe([$this->instagram->id, ContentType::InstagramReel, CreativeKind::Video, ($this->weekDay)(1)])
        ->and($shape('Network instead of key'))->toBe([$this->linkedin->id, ContentType::LinkedInPost, CreativeKind::Carousel, ($this->weekDay)(1)])
        ->and($shape('Reel of another network'))->toBe([$this->instagram->id, ContentType::InstagramReel, CreativeKind::Video, ($this->weekDay)(2)])
        // Monday is Momo Monday's, Tuesday and Wednesday are taken: Thursday is the nearest free day.
        ->and($shape('Same day as Momo Monday'))->toBe([$this->instagram->id, ContentType::InstagramReel, CreativeKind::Video, ($this->weekDay)(3)])
        // Into the event's days (or the 3 before), on LinkedIn's nearest free one.
        ->and($shape('Dashain by its name'))->toBe([$this->linkedin->id, ContentType::LinkedInPost, CreativeKind::Image, ($this->weekDay)(2)])
        ->and($items['Dashain by its name']->event_id)->toBe($dashain->id)
        ->and($items['Dashain by its name']->pillar)->toBe('Menu')
        ->and($shape('On a day off'))->toBe([$this->instagram->id, ContentType::InstagramReel, CreativeKind::Video, ($this->weekDay)(4)])
        ->and(collect($plan->generation_weeks)->sum('repaired'))->toBe(9);
});

test('the job refuses what it can\'t repair, with the reason', function () {
    BrandProfile::query()->where('workspace_id', $this->workspace->id)->update(['cadence' => [$this->instagram->id => 3, $this->linkedin->id => 0]]);
    // Instagram posts on Mondays only.
    $this->instagram->update(['posting_schedule' => collect(range(0, 6))->reduce(
        fn (PostingSchedule $schedule, int $day): PostingSchedule => $schedule->withDayEnabled($day, $day === CarbonImmutable::MONDAY),
        PostingSchedule::empty(),
    )]);
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->weekDay)(0), 'title' => 'Momo Monday']);
    // Next Tuesday: its post may go out from Sunday on, and Instagram doesn't post on Sundays.
    CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'key' => 'dashain', 'starts_on' => $this->monday->addDays(9), 'ends_on' => $this->monday->addDays(9)]);

    fakePlannerWeeks([
        ($this->suggestion)(['date' => ($this->weekDay)(1), 'channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'title' => 'Paused channel']),
        ($this->suggestion)(['date' => ($this->weekDay)(3), 'event_key' => 'dashain', 'title' => 'Dashain too early']),
        ($this->suggestion)(['date' => ($this->weekDay)(4), 'title' => ' momo MONDAY ']),
        ($this->suggestion)(['date' => ($this->weekDay)(1), 'title' => 'Tuesday treat']),
    ]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();

    expect($plan->items()->where('status', ItemStatus::Suggested)->count())->toBe(0)
        ->and(collect($plan->generation_rejections)->pluck('reason', 'title')->all())->toBe([
            'Paused channel' => 'channel_paused',
            'Dashain too early' => 'event_date_mismatch',
            'momo MONDAY' => 'duplicate_title',
            // Monday, the only day Instagram posts, already has Momo Monday.
            'Tuesday treat' => 'day_off',
        ]);
});

test('a month is drafted one week per AI call, each told its days and what every channel still needs', function () {
    $prompts = fakePlannerWeeks([($this->suggestion)(['date' => ($this->weekDay)(8)])]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $first = collect($prompts)->reject(fn (array $prompt): bool => isset($prompt['retry']))->values();
    $ranges = $first->map(fn (array $prompt): array => $prompt['range']);
    // The month's second whole week: never joined with a short piece at either end of the month.
    $mondayWeek = $first->first(fn (array $prompt): bool => $prompt['range']['from'] <= ($this->weekDay)(8) && $prompt['range']['to'] >= ($this->weekDay)(8));

    expect($first->count())->toBeBetween(4, 5)
        ->and($ranges->first()['from'])->toBe($this->month->toDateString())
        ->and($ranges->last()['to'])->toBe($this->month->endOfMonth()->toDateString())
        // Back to back, none shorter than 3 days or longer than 9.
        ->and($ranges->sliding(2)->every(fn ($pair): bool => CarbonImmutable::parse($pair->first()['to'])->addDay()->toDateString() === $pair->last()['from']))->toBeTrue()
        ->and($ranges->every(fn (array $range): bool => CarbonImmutable::parse($range['from'])->diffInDays(CarbonImmutable::parse($range['to'])) + 1 >= 3))->toBeTrue()
        ->and($ranges->every(fn (array $range): bool => CarbonImmutable::parse($range['from'])->diffInDays(CarbonImmutable::parse($range['to'])) + 1 <= 9))->toBeTrue()
        ->and($mondayWeek['range'])->toBe(['from' => ($this->weekDay)(7), 'to' => ($this->weekDay)(13)])
        ->and(collect($mondayWeek['days'])->count())->toBe(7)
        ->and(collect($mondayWeek['channels'])->pluck('posts_to_plan', 'key')->all())->toBe([$this->instagram->id => 3, $this->linkedin->id => SuggestionChecks::DEFAULT_POSTS_PER_WEEK])
        ->and(collect($plan->generation_weeks)->pluck('status')->unique()->all())->toBe(['done'])
        ->and(collect($plan->generation_weeks)->count())->toBe($first->count())
        ->and($plan->items()->where('status', ItemStatus::Suggested)->count())->toBe(1);

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.added', 1)
            ->where('plan.generation.weeks.0.status', 'done')
            ->where('plan.generation.weeks.0.error', null));
});

test('a week with room left gets one more try, told what was refused and why', function () {
    $prompts = fakePlannerWeeks(
        [
            ($this->suggestion)(['date' => ($this->weekDay)(1), 'title' => 'Kept the first time']),
            ($this->suggestion)(['date' => ($this->weekDay)(2), 'channel' => 'not-a-channel', 'content_type' => ContentType::XPost->value, 'title' => 'Nowhere to go']),
        ],
        retry: [
            ($this->suggestion)(['date' => ($this->weekDay)(3), 'title' => 'Second try one']),
            ($this->suggestion)(['date' => ($this->weekDay)(5), 'title' => 'Second try two']),
        ],
    );

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), ['from' => ($this->weekDay)(0), 'to' => ($this->weekDay)(6)])
        ->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $retry = collect($prompts)->filter(fn (array $prompt): bool => isset($prompt['retry']))->values();
    $instagram = collect($retry->first()['channels'])->firstWhere('key', $this->instagram->id);

    expect($prompts)->toHaveCount(2)
        ->and($retry)->toHaveCount(1)
        ->and($retry->first()['retry']['rejected'])->toBe([['title' => 'Nowhere to go', 'date' => ($this->weekDay)(2), 'channel' => 'not-a-channel', 'reason' => 'unknown_channel']])
        // The first answer's item is planned now, and its day is no longer free.
        ->and($instagram['posts_to_plan'])->toBe(2)
        ->and($instagram['free_days'])->not->toContain(($this->weekDay)(1))
        ->and(collect($retry->first()['already_planned'])->pluck('title')->all())->toContain('Kept the first time')
        ->and($plan->items()->where('status', ItemStatus::Suggested)->orderBy('scheduled_on')->pluck('title')->all())->toBe(['Kept the first time', 'Second try one', 'Second try two'])
        ->and(collect($plan->generation_rejections)->pluck('reason', 'title')->all())->toBe(['Nowhere to go' => 'unknown_channel'])
        ->and($plan->generation_weeks[0])->toMatchArray(['status' => 'done', 'proposed' => 4, 'added' => 3, 'not_added' => 1, 'calls' => 2]);

    expect((new ContentPlanner(Locale::DEFAULT, retry: true))->instructions())->toContain('second try')
        ->and((new ContentPlanner(Locale::DEFAULT))->instructions())->not->toContain('second try');
});

test('a week with nothing left to plan gets no second try', function () {
    BrandProfile::query()->where('workspace_id', $this->workspace->id)->update(['cadence' => [$this->instagram->id => 1, $this->linkedin->id => 0]]);
    $prompts = fakePlannerWeeks([
        ($this->suggestion)(['date' => ($this->weekDay)(1)]),
        ($this->suggestion)(['date' => ($this->weekDay)(3), 'title' => 'One too many']),
    ]);

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), ['from' => ($this->weekDay)(0), 'to' => ($this->weekDay)(6)])
        ->assertSessionHasNoErrors();

    expect($prompts)->toHaveCount(1)
        ->and(collect($this->plan->fresh()->generation_rejections)->pluck('reason', 'title')->all())->toBe(['One too many' => 'over_cadence']);
});

test('a failed call is tried once more, and a week that still fails keeps its suggestions while the others are saved', function () {
    Exceptions::fake();
    $kept = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->weekDay)(9), 'title' => 'Earlier idea for week two']);
    $calls = collect();
    ContentPlanner::fake(function (string $prompt) use ($calls): array {
        $context = json_decode($prompt, true);
        $calls->push($context['range']['from']);

        // The first week answers on its second call, the second week never does.
        if ($context['range']['from'] === ($this->weekDay)(7) || $calls->count() === 1) {
            throw new AiException('Provider overloaded');
        }

        return ['summary' => 'Week one.', 'items' => isset($context['retry']) ? [] : [($this->suggestion)(['date' => ($this->weekDay)(1)])]];
    });

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), ['from' => ($this->weekDay)(0), 'to' => ($this->weekDay)(13)])
        ->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();

    expect($plan->generation_status)->toBe(GenerationStatus::Idle)
        ->and($plan->generation_summary)->toBe('Week one.')
        ->and($plan->items()->where('status', ItemStatus::Suggested)->pluck('title')->sort()->values()->all())->toBe(['Earlier idea for week two', 'Momo folding time-lapse'])
        ->and($kept->fresh())->not->toBeNull()
        ->and($plan->generation_weeks[0])->toMatchArray(['status' => 'done', 'calls' => 2])
        ->and($plan->generation_weeks[1])->toMatchArray(['from' => ($this->weekDay)(7), 'to' => ($this->weekDay)(13), 'status' => 'failed', 'error' => 'ai_failed', 'calls' => 2]);

    Exceptions::assertReported(AiException::class);

    $this->actingAs($this->member)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.status', 'idle')
            ->where('plan.generation.weeks.1.status', 'failed')
            ->where('plan.generation.weeks.1.error', __('socialhq_plan.generation.week_errors.ai_failed')));
});

test('a run that runs out of time lists the weeks it didn\'t reach as not drafted', function () {
    fakePlannerWeeks([($this->suggestion)(['date' => ($this->weekDay)(1)])], whileAsked: fn (array $prompt, int $call) => $call === 1
        ? $this->travel(GeneratePlanSuggestions::BUDGET_SECONDS + 1)->seconds()
        : null);

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), ['from' => ($this->weekDay)(0), 'to' => ($this->weekDay)(13)])
        ->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();

    expect($plan->generation_status)->toBe(GenerationStatus::Idle)
        ->and($plan->items()->where('status', ItemStatus::Suggested)->count())->toBe(1)
        ->and(collect($plan->generation_weeks)->map(fn (array $week): array => Arr::only($week, ['status', 'error', 'calls']))->all())->toBe([
            ['status' => 'done', 'error' => null, 'calls' => 1],
            ['status' => 'failed', 'error' => 'out_of_time', 'calls' => 0],
        ]);
});

test('the planner schema lists every key as required, as strict providers want', function () {
    $schema = (new ContentPlanner(Locale::DEFAULT))->schema(new JsonSchemaTypeFactory);
    $item = data_get($schema['items']->toArray(), 'items');

    expect($item['required'])->toEqualCanonicalizing(['date', 'channel', 'content_type', 'creative', 'title', 'pillar', 'objective', 'angle', 'event_key', 'suggested_time']);
});

test('an answer without items is logged with who answered and how it stopped', function () {
    Exceptions::fake();
    ContentPlanner::fake(fn () => ['summary' => 'Cut short.']);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    expect($this->plan->fresh()->generation_error)->toBe('invalid_output');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'Content plan draft without items: provider'));
});

test('an answer without items that stopped at its length limit tells the planner to try fewer days', function () {
    $answer = fn (FinishReason $reason): TextResponse => (new TextResponse('', new Usage(10, 12000), new Meta('litellm', 'shq-structured')))
        ->withSteps(collect([new Step('', [], [], $reason, new Usage(10, 12000), new Meta('litellm', 'shq-structured'))]));

    expect(GeneratePlanSuggestions::stoppedAtLimit($answer(FinishReason::Length)))->toBeTrue()
        ->and(GeneratePlanSuggestions::stoppedAtLimit($answer(FinishReason::Stop)))->toBeFalse()
        ->and(GeneratePlanSuggestions::stoppedAtLimit(['summary' => 'Not a response']))->toBeFalse();

    $this->plan->update(['generation_status' => GenerationStatus::Failed, 'generation_error' => 'cut_off']);

    $this->actingAs($this->member)->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.error', __('socialhq_plan.generation.errors.cut_off')));
});

test('a new draft keeps a suggestion someone already started work on', function () {
    $started = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['title' => 'Started']);
    ItemVersion::factory()->for($started, 'item')->create();
    $untouched = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['title' => 'Untouched']);
    fakePlannerWeeks([($this->suggestion)()]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    expect(PlanItem::query()->whereKey($started->id)->exists())->toBeTrue()
        ->and(PlanItem::query()->whereKey($untouched->id)->exists())->toBeFalse();
});

test('a run may plan the lead-up to an event just after its days', function () {
    $event = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'key' => 'tihar', 'starts_on' => $this->month->addDays(12), 'ends_on' => $this->month->addDays(12)]);
    $this->plan->update(['generation_options' => ['from' => ($this->day)(3), 'to' => ($this->day)(10), 'old_calendars' => false, 'past_posts' => false, 'audience_events' => false]]);

    expect(collect(PlannerContext::build($this->plan->fresh(), CarbonImmutable::now('UTC'))['events'])->pluck('key')->all())->toBe(['tihar']);

    fakePlannerWeeks([($this->suggestion)(['date' => ($this->day)(10), 'event_key' => 'tihar', 'title' => 'Tihar is coming'])]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey), ['from' => ($this->day)(3), 'to' => ($this->day)(10)])->assertSessionHasNoErrors();

    expect($this->plan->fresh()->items()->sole()->event_id)->toBe($event->id);
});

test('a stale run outlives the job\'s timeout', function () {
    expect(ContentPlan::STALE_MINUTES * 60)->toBeGreaterThan((new GeneratePlanSuggestions(1, 'run'))->timeout);
});

test('kept suggestions count toward a channel\'s posts per week, and the AI is shown them', function () {
    // A whole week inside the month, starting on the requester's first day of the week (Monday).
    $monday = $this->month->addDays(7)->startOfWeek(CarbonImmutable::MONDAY);
    $started = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => $monday->toDateString(), 'title' => 'Started']);
    ItemVersion::factory()->for($started, 'item')->create();
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => $monday->addDay()->toDateString(), 'title' => 'Planned one']);
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => $monday->addDays(2)->toDateString(), 'title' => 'Planned two']);

    expect(collect(PlannerContext::build($this->plan, CarbonImmutable::today('UTC'))['already_planned'])->pluck('title')->all())->toContain('Started');

    $prompts = fakePlannerWeeks([
        ($this->suggestion)(['date' => $monday->addDays(3)->toDateString(), 'title' => 'A fourth that week']),
        ($this->suggestion)(['date' => $monday->addDays(7)->toDateString(), 'title' => 'Next week']),
    ]);

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), ['from' => $monday->toDateString(), 'to' => $monday->addDays(13)->toDateString()])
        ->assertSessionHasNoErrors();

    expect($this->plan->items()->where('status', ItemStatus::Suggested)->pluck('title')->sort()->values()->all())->toBe(['Next week', 'Started'])
        ->and(collect($this->plan->fresh()->generation_rejections)->pluck('reason', 'title')->all())->toBe(['A fourth that week' => 'over_cadence'])
        // The AI was told that week needs nothing more on Instagram.
        ->and(collect(collect($prompts)->first(fn (array $prompt): bool => $prompt['range']['from'] <= $monday->toDateString() && $prompt['range']['to'] >= $monday->toDateString())['channels'])->firstWhere('key', $this->instagram->id)['posts_to_plan'])->toBe(0);
});
