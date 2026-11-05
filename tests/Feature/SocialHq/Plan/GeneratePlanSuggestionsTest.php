<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\User\Locale;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Plan\Ai\ContentPlanner;
use App\SocialHq\Plan\Ai\PlannerContext;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\GenerationStatus;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Plan\PlanStatus;
use Carbon\CarbonImmutable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
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
    $planned = PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(2), 'title' => 'Kept']);
    $dropped = PlanItem::factory()->for($this->plan, 'plan')->create(['status' => ItemStatus::Dropped]);
    $oldSuggestion = PlanItem::factory()->for($this->plan, 'plan')->suggested()->create();
    $festival = CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'key' => 'dashain', 'starts_on' => $this->month->addDays(12), 'ends_on' => $this->month->addDays(16)]);
    CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'key' => 'tihar', 'starts_on' => $this->month->addDays(20), 'ends_on' => $this->month->addDays(21)]);

    ContentPlanner::fake([[
        'summary' => 'A month about the menu.',
        'items' => [
            ($this->suggestion)(),
            ($this->suggestion)(['date' => ($this->day)(11), 'channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'event_key' => 'dashain', 'pillar' => 'Memes', 'title' => 'Dashain greetings']),
            ($this->suggestion)(['date' => $this->month->addMonthNoOverflow()->toDateString(), 'title' => 'Too late']),
            ($this->suggestion)(['channel' => SocialAccount::factory()->instagram()->create()->id, 'title' => 'Wrong channel']),
            ($this->suggestion)(['content_type' => ContentType::XPost->value, 'title' => 'Wrong format']),
            ($this->suggestion)(['date' => ($this->day)(19), 'event_key' => 'tihar', 'title' => 'Unconfirmed festival']),
            ($this->suggestion)(['date' => ($this->day)(8), 'event_key' => 'made-up-day', 'title' => 'Invented day']),
            ($this->suggestion)(['date' => ($this->day)(2), 'title' => 'Same day as a planned post']),
            ($this->suggestion)(['title' => 'Same day as another suggestion']),
        ],
    ]]);

    $this->actingAs($this->member)
        ->post(route('socialhq.plan.generate', $this->monthKey), ['goals' => 'Lean on Dashain.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $suggestions = $plan->items()->where('status', ItemStatus::Suggested)->orderBy('scheduled_on')->get();

    expect($plan->generation_status)->toBe(GenerationStatus::Idle)
        ->and($plan->goals)->toBe('Lean on Dashain.')
        ->and($plan->generation_summary)->toBe('A month about the menu.')
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
            'Wrong format' => 'content_type_not_for_channel',
            'Unconfirmed festival' => 'unconfirmed_event',
            'Invented day' => 'unknown_event',
            'Same day as a planned post' => 'duplicate_day',
            'Same day as another suggestion' => 'duplicate_day',
        ]);

    ContentPlanner::assertPrompted(fn ($prompt) => $prompt->contains('Lean on Dashain.') && $prompt->contains('dashain') && $prompt->contains('A Kathmandu momo chain.'));
});

test('new suggestions send an approved plan back for review', function () {
    $this->plan->update(['status' => PlanStatus::Approved, 'approved_at' => now()]);
    ContentPlanner::fake([['summary' => 'More.', 'items' => [($this->suggestion)()]]]);

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
    ContentPlanner::fake([['summary' => 'Fresh.', 'items' => []]]);

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertStatus(Response::HTTP_CONFLICT);
    ContentPlanner::assertNeverPrompted();

    $this->plan->update(['generation_started_at' => now()->subMinutes(ContentPlan::STALE_MINUTES + 1)]);

    $this->actingAs($this->owner)
        ->get(route('socialhq.plan.show', $this->monthKey))
        ->assertInertia(fn ($page) => $page->where('plan.generation.status', 'failed'));

    $this->actingAs($this->owner)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();
    expect($this->plan->fresh()->generation_status)->toBe(GenerationStatus::Idle)
        ->and($this->plan->fresh()->generation_summary)->toBe('Fresh.');
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
        ->and($context['channels'][0]['content_types'])->toContain(ContentType::InstagramReel->value)
        ->and($context['channels'][1]['posts_per_week'])->toBeNull()
        ->and($context['events'])->toBe([['key' => 'tihar', 'name' => $context['events'][0]['name'], 'category' => 'festival', 'starts_on' => ($this->day)(20), 'ends_on' => ($this->day)(21), 'confirmed' => false]])
        ->and($context['already_planned'])->toBe([['title' => 'Already planned', 'date' => ($this->day)(1), 'channel' => $this->instagram->id]])
        ->and($context['past_posts'])->toHaveCount(PlannerContext::PAST_POSTS)
        ->and($context['past_posts'][0]['engagement'])->toBe(640)
        ->and($context['past_posts'][30]['engagement'])->toBe(10)
        ->and(mb_strlen($context['past_posts'][0]['excerpt']))->toBeLessThanOrEqual(PlannerContext::EXCERPT_LENGTH);
});

test('a run that ends after the plan was approved sends it back for review', function () {
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(1)]);
    ContentPlanner::fake(function () {
        // The plan is approved while the AI works.
        $this->plan->fresh()->forceFill(['status' => PlanStatus::Approved, 'approved_at' => now(), 'approved_by' => $this->owner->id])->save();

        return ['summary' => 'Late.', 'items' => [($this->suggestion)()]];
    });

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

test('an item planned while the AI worked still blocks its channel\'s day', function () {
    ContentPlanner::fake(function () {
        PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(6), 'title' => 'Added meanwhile']);

        return ['summary' => 'x', 'items' => [($this->suggestion)()]];
    });

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    expect($this->plan->items()->where('status', ItemStatus::Suggested)->count())->toBe(0)
        ->and(collect($this->plan->fresh()->generation_rejections)->pluck('reason')->all())->toBe(['duplicate_day']);
});

test('in the current month the AI is told today and days before it are refused', function () {
    $this->travelTo(CarbonImmutable::now('UTC')->startOfMonth()->addDays(14)->setTime(12, 0));
    $month = CarbonImmutable::now('UTC')->startOfMonth();
    $today = CarbonImmutable::now('UTC')->toDateString();
    $this->member->update(['timezone' => 'UTC']);
    ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $month->toDateString()]);
    ContentPlanner::fake([['summary' => 'Now.', 'items' => [
        ($this->suggestion)(['date' => $month->addDays(13)->toDateString(), 'title' => 'Yesterday']),
        ($this->suggestion)(['date' => $today, 'title' => 'Today']),
    ]]]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $month->format('Y-m')))->assertSessionHasNoErrors();

    $plan = ContentPlan::query()->where('workspace_id', $this->workspace->id)->whereDate('month', $month->toDateString())->sole();

    expect($plan->items()->pluck('title')->all())->toBe(['Today'])
        ->and(collect($plan->generation_rejections)->pluck('reason', 'title')->all())->toBe(['Yesterday' => 'date_in_past']);

    ContentPlanner::assertPrompted(fn ($prompt) => $prompt->contains("\"today\":\"{$today}\""));
});

test('the job holds the AI to the cadence, the formats, the event dates and the titles already used', function () {
    BrandProfile::query()->where('workspace_id', $this->workspace->id)->update(['cadence' => [$this->instagram->id => 3, $this->linkedin->id => 0]]);
    PlanItem::factory()->for($this->plan, 'plan')->create(['social_account_id' => $this->instagram->id, 'scheduled_on' => ($this->day)(1), 'title' => 'Momo Monday']);
    CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'key' => 'dashain', 'starts_on' => $this->month->addDays(12), 'ends_on' => $this->month->addDays(16)]);

    ContentPlanner::fake([['summary' => 'Checked.', 'items' => [
        ($this->suggestion)(['channel' => $this->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'creative' => 'image', 'title' => 'Paused channel']),
        ($this->suggestion)(['date' => ($this->day)(3), 'creative' => 'image', 'title' => 'Image as a reel']),
        ($this->suggestion)(['date' => ($this->day)(4), 'content_type' => ContentType::InstagramFeed->value, 'creative' => 'none', 'title' => 'No media on the feed']),
        ($this->suggestion)(['date' => ($this->day)(5), 'event_key' => 'dashain', 'title' => 'Dashain too early']),
        ($this->suggestion)(['date' => ($this->day)(17), 'event_key' => 'dashain', 'title' => 'Dashain too late']),
        ($this->suggestion)(['date' => ($this->day)(9), 'event_key' => 'dashain', 'title' => 'Dashain countdown']),
        ($this->suggestion)(['date' => ($this->day)(20), 'title' => ' momo MONDAY ']),
        ($this->suggestion)(['date' => ($this->day)(21), 'title' => 'Dashain Countdown']),
        // A model may leave out what isn't required (pillar, event_key, objective, angle).
        ['date' => ($this->day)(23), 'channel' => $this->instagram->id, 'content_type' => ContentType::InstagramFeed->value, 'creative' => 'carousel', 'title' => 'Only the required fields'],
    ]]]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    $plan = $this->plan->fresh();
    $minimal = $plan->items()->where('title', 'Only the required fields')->sole();

    expect($plan->items()->where('status', ItemStatus::Suggested)->orderBy('scheduled_on')->pluck('title')->all())->toBe(['Dashain countdown', 'Only the required fields'])
        ->and($minimal->pillar)->toBeNull()
        ->and($minimal->event_id)->toBeNull()
        ->and($minimal->objective)->toBeNull()
        ->and($minimal->angle)->toBeNull()
        ->and(collect($plan->generation_rejections)->pluck('reason', 'title')->all())->toBe([
            'Paused channel' => 'channel_paused',
            'Image as a reel' => 'creative_mismatch',
            'No media on the feed' => 'creative_mismatch',
            'Dashain too early' => 'event_date_mismatch',
            'Dashain too late' => 'event_date_mismatch',
            'momo MONDAY' => 'duplicate_title',
            'Dashain Countdown' => 'duplicate_title',
        ]);
});

test('the planner schema lists every key as required, as strict providers want', function () {
    $schema = (new ContentPlanner(Locale::DEFAULT))->schema(new JsonSchemaTypeFactory);
    $item = data_get($schema['items']->toArray(), 'items');

    expect($item['required'])->toEqualCanonicalizing(['date', 'channel', 'content_type', 'creative', 'title', 'pillar', 'objective', 'angle', 'event_key']);
});

test('an answer without items is logged with who answered and how it stopped', function () {
    Exceptions::fake();
    ContentPlanner::fake([['summary' => 'Cut short.']]);

    $this->actingAs($this->member)->post(route('socialhq.plan.generate', $this->monthKey))->assertSessionHasNoErrors();

    expect($this->plan->fresh()->generation_error)->toBe('invalid_output');
    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'Content plan draft without items: provider'));
});
