<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\Keyword;
use App\SocialHq\Knowledge\Keywords\KeywordCheck;
use App\SocialHq\Knowledge\Keywords\KeywordPicker;
use App\SocialHq\Knowledge\Keywords\PostContext;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Plan\Ai\CopyWriter;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\PlanItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'about' => 'Payroll and HR software for Nepali companies.', 'pillars' => ['Product tips', 'Culture']]);
});

/**
 * A dated event the brand sees, as the Events page imports it.
 */
function catalogueEvent(Workspace $workspace, string $name, CarbonImmutable $day, ?string $key = null): CalendarEvent
{
    return CalendarEvent::factory()->create([
        'account_id' => $workspace->account_id,
        'name' => $name,
        'key' => $key ?? Str::slug($name),
        'starts_on' => $day->toDateString(),
        'ends_on' => $day->toDateString(),
    ]);
}

/**
 * @return list<string>
 */
function pickedTexts(Workspace $workspace, PostContext $context): array
{
    return array_column(KeywordPicker::pick($workspace, $context), 'text');
}

test('the migration moves each saved list to the catalogue as it was: text, main and order', function () {
    $migration = require database_path('migrations/socialhq/2026_11_26_001001_create_shq_keywords_tables.php');
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    BrandKeywords::save($this->workspace, [['text' => 'payroll software nepal', 'main' => true, 'tags' => [['kind' => 'pillar', 'value' => 'Culture']]]]);

    // Back to the JSON list (tags have nowhere to go), then the list as PR #58 saved it.
    $migration->down();
    expect(json_decode((string) DB::table('shq_brand_profiles')->where('workspace_id', $this->workspace->id)->value('keywords'), true))
        ->toBe([['text' => 'payroll software nepal', 'main' => true]]);

    DB::table('shq_brand_profiles')->where('workspace_id', $this->workspace->id)->update(['keywords' => json_encode([
        ['text' => 'hr software nepal', 'main' => false],
        ['text' => 'payroll software nepal', 'main' => true],
        ['text' => 'café attendance app', 'main' => false],
        ['text' => 'leave management', 'main' => true],
    ])]);
    DB::table('shq_brand_profiles')->insert(['workspace_id' => $other->id, 'keywords' => json_encode([['text' => 'momo kathmandu', 'main' => true]]), 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(BrandKeywords::forWorkspace($this->workspace))->toBe([
        ['text' => 'hr software nepal', 'main' => false],
        ['text' => 'payroll software nepal', 'main' => true],
        ['text' => 'café attendance app', 'main' => false],
        ['text' => 'leave management', 'main' => true],
    ])
        ->and(BrandKeywords::forWorkspace($other))->toBe([['text' => 'momo kathmandu', 'main' => true]])
        ->and(Keyword::query()->where('workspace_id', $this->workspace->id)->pluck('match_key')->all())->toContain('cafe attendance app');
});

test('an admin tags keywords with pillars, products, events and months, and the page reads them back', function () {
    $product = KnowledgeEntry::query()->create(['workspace_id' => $this->workspace->id, 'kind' => 'product', 'title' => 'Payroll module', 'body' => 'Runs payroll.', 'use_in_ai' => true]);
    $fact = KnowledgeEntry::query()->create(['workspace_id' => $this->workspace->id, 'kind' => 'fact', 'title' => 'Founded', 'body' => '2015.', 'use_in_ai' => true]);
    $elsewhere = KnowledgeEntry::query()->create(['workspace_id' => Workspace::factory()->create()->id, 'kind' => 'product', 'title' => 'Not ours', 'body' => 'x', 'use_in_ai' => true]);
    catalogueEvent($this->workspace, 'Vijaya Dashami (Dashain Tika)', CarbonImmutable::today()->addDays(20));

    $this->actingAs($this->owner)
        ->put(route('socialhq.brand.keywords.update'), ['keywords' => [
            ['text' => 'payroll software nepal', 'main' => true, 'tags' => []],
            ['text' => 'payroll for dashain bonus', 'main' => false, 'tags' => [
                ['kind' => 'event', 'value' => 'vijaya-dashami-dashain-tika-2083', 'label' => 'Vijaya Dashami (Dashain Tika)'],
                ['kind' => 'product', 'entry_id' => $product->id],
                // Not a product, and another brand's: dropped.
                ['kind' => 'product', 'entry_id' => $fact->id],
                ['kind' => 'product', 'entry_id' => $elsewhere->id],
            ]],
            ['text' => 'tax year end payroll', 'main' => false, 'tags' => [['kind' => 'months', 'from' => 6, 'to' => 7], ['kind' => 'pillar', 'value' => 'Product tips'], ['kind' => 'pillar', 'value' => 'Product tips']]],
        ]])
        ->assertRedirect();

    $catalogue = BrandKeywords::catalogue($this->workspace);

    expect($catalogue[0]['tags'])->toBe([])
        ->and(collect($catalogue[1]['tags'])->map(fn (array $tag): array => [$tag['kind'], $tag['label']])->all())->toBe([
            // The event's key loses its year: next year's dates still match.
            ['event', 'Vijaya Dashami (Dashain Tika)'],
            ['product', 'Payroll module'],
        ])
        ->and($catalogue[1]['tags'][0]['value'])->toBe('vijaya-dashami-dashain-tika')
        ->and(collect($catalogue[2]['tags'])->pluck('label')->all())->toBe(['June to July', 'Product tips']);

    $this->actingAs($this->owner)->get(route('socialhq.brand.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('keywords.items.1.tags.1.label', 'Payroll module')
            ->where('keywords.occasions.0.key', 'vijaya-dashami-dashain-tika')
            ->where('keywords.occasion_days', ['before' => 14, 'after' => 2]));

    // Deleting the product removes its tag.
    $product->delete();

    expect(collect(BrandKeywords::catalogue($this->workspace)[1]['tags'])->pluck('kind')->all())->toBe(['event']);
});

test('a tag says what it needs, or the list is not saved', function (array $tag, string $error) {
    $this->actingAs($this->owner)
        ->from(route('socialhq.brand.index'))
        ->put(route('socialhq.brand.keywords.update'), ['keywords' => [['text' => 'payroll software', 'main' => false, 'tags' => [$tag]]]])
        ->assertSessionHasErrors($error);

    expect(BrandKeywords::isEmpty($this->workspace))->toBeTrue();
})->with([
    'unknown kind' => [['kind' => 'channel', 'value' => 'Instagram'], 'keywords.0.tags.0.kind'],
    'pillar without a name' => [['kind' => 'pillar'], 'keywords.0.tags.0.value'],
    'product without an entry' => [['kind' => 'product'], 'keywords.0.tags.0.entry_id'],
    'month out of range' => [['kind' => 'months', 'from' => 13, 'to' => 2], 'keywords.0.tags.0.from'],
]);

test('a keyword that stays keeps its row and the day it was added; removed ones go', function () {
    BrandKeywords::save($this->workspace, [['text' => 'payroll software', 'main' => true], ['text' => 'leave app', 'main' => false]]);
    $first = Keyword::query()->where('text', 'payroll software')->firstOrFail();
    $first->forceFill(['created_at' => now()->subDays(90)])->save();

    BrandKeywords::save($this->workspace, [
        ['text' => 'attendance app', 'main' => false],
        ['text' => 'Payroll Software', 'main' => false, 'tags' => [['kind' => 'pillar', 'value' => 'Culture']]],
    ]);

    $kept = Keyword::query()->where('match_key', 'payroll software')->firstOrFail();

    expect($kept->id)->toBe($first->id)
        ->and($kept->created_at->lessThan(now()->subDays(80)))->toBeTrue()
        ->and($kept->main)->toBeFalse()
        ->and($kept->position)->toBe(1)
        ->and($kept->tags()->pluck('value')->all())->toBe(['Culture'])
        ->and(BrandKeywords::forWorkspace($this->workspace))->toBe([
            ['text' => 'attendance app', 'main' => false],
            ['text' => 'payroll software', 'main' => false],
        ]);
});

test('a Dashain greeting gets the Dashain keyword, not the product ones; general ones fill in', function () {
    $dashain = CarbonImmutable::today()->addDays(40);
    catalogueEvent($this->workspace, 'Vijaya Dashami (Dashain Tika)', $dashain);
    BrandKeywords::save($this->workspace, [
        ['text' => 'payroll software nepal', 'main' => true],
        ['text' => 'dashain greetings', 'main' => false, 'tags' => [['kind' => 'event', 'value' => 'vijaya-dashami-dashain-tika', 'label' => 'Vijaya Dashami']]],
        ['text' => 'payroll tips', 'main' => false, 'tags' => [['kind' => 'pillar', 'value' => 'Product tips']]],
        ['text' => 'hr team nepal', 'main' => false],
        ['text' => 'nepali workplace', 'main' => false],
    ]);

    // Ten days before Dashain, a post about the greeting.
    $picks = KeywordPicker::pick($this->workspace, PostContext::forTopic('Dashain greeting from our team', $dashain->subDays(10)));

    expect(array_column($picks, 'text'))->toBe(['payroll software nepal', 'dashain greetings', 'hr team nepal'])
        ->and($picks[1]['matched'])->toBe([['kind' => 'event', 'label' => 'Vijaya Dashami']])
        ->and($picks[2]['matched'])->toBe([]);

    // Outside the window the Dashain keyword stays out, and general ones fill both places.
    expect(pickedTexts($this->workspace, PostContext::forTopic('Dashain greeting from our team', $dashain->subDays(15))))
        ->not->toContain('dashain greetings')
        ->toContain('hr team nepal', 'nepali workplace');

    // A product tip gets the pillar's keyword.
    expect(pickedTexts($this->workspace, new PostContext(pillar: 'product tips', day: $dashain->subMonths(1))))
        ->toContain('payroll tips')
        ->not->toContain('dashain greetings');
});

test('an event tag is in season from 14 days before to 2 days after, every year', function () {
    $thisYear = CarbonImmutable::today()->addDays(30);
    $nextYear = $thisYear->addDays(355);
    catalogueEvent($this->workspace, 'Nepali New Year 2083', $thisYear, 'nepali-new-year-2083');
    catalogueEvent($this->workspace, 'Nepali New Year 2084', $nextYear, 'nepali-new-year-2084');
    BrandKeywords::save($this->workspace, [
        ['text' => 'new year offers', 'main' => false, 'tags' => [['kind' => 'event', 'value' => 'nepali-new-year-2083', 'label' => 'Nepali New Year 2083']]],
    ]);

    $inSeason = fn (CarbonImmutable $day): bool => in_array('new year offers', pickedTexts($this->workspace, PostContext::forTopic(null, $day)), true);

    expect($inSeason($thisYear->subDays(14)))->toBeTrue()
        ->and($inSeason($thisYear->subDays(15)))->toBeFalse()
        ->and($inSeason($thisYear->addDays(2)))->toBeTrue()
        ->and($inSeason($thisYear->addDays(3)))->toBeFalse()
        // Next year's date has another key, the same occasion.
        ->and($inSeason($nextYear->subDays(5)))->toBeTrue();

    config(['socialhq.knowledge.keywords.occasion.days_before' => 20]);

    expect($inSeason($thisYear->subDays(18)))->toBeTrue();
});

test('a range of months may run over the new year, and an item\'s own event always counts', function () {
    BrandKeywords::save($this->workspace, [
        ['text' => 'winter attendance', 'main' => false, 'tags' => [['kind' => 'months', 'from' => 11, 'to' => 2]]],
        ['text' => 'teej leave', 'main' => false, 'tags' => [['kind' => 'event', 'value' => 'haritalika-teej', 'label' => 'Teej']]],
    ]);
    $year = CarbonImmutable::today()->year + 1;

    expect(pickedTexts($this->workspace, PostContext::forTopic(null, CarbonImmutable::create($year, 1, 15))))->toBe(['winter attendance'])
        ->and(pickedTexts($this->workspace, PostContext::forTopic(null, CarbonImmutable::create($year, 3, 15))))->toBe([])
        // No date of Teej is listed, but the item is for it.
        ->and(pickedTexts($this->workspace, new PostContext(occasion: 'haritalika-teej', day: CarbonImmutable::create($year, 3, 15))))->toBe(['teej leave']);
});

test('a post about a product gets that product\'s keywords', function () {
    $product = KnowledgeEntry::query()->create(['workspace_id' => $this->workspace->id, 'kind' => 'product', 'title' => 'Attendance kiosk app', 'body' => 'Face check-in.', 'use_in_ai' => true]);
    BrandKeywords::save($this->workspace, [
        ['text' => 'face attendance', 'main' => false, 'tags' => [['kind' => 'product', 'entry_id' => $product->id]]],
        ['text' => 'hr software', 'main' => false],
    ]);

    expect(pickedTexts($this->workspace, PostContext::forTopic('How the attendance kiosk speeds up mornings')))->toBe(['face attendance', 'hr software'])
        ->and(pickedTexts($this->workspace, PostContext::forTopic('Our leave policy explained')))->toBe(['hr software']);
});

test('posts take turns with the main keywords', function () {
    BrandKeywords::save($this->workspace, [
        ['text' => 'payroll software nepal', 'main' => true],
        ['text' => 'hr software nepal', 'main' => true],
        ['text' => 'attendance system nepal', 'main' => true],
    ]);
    $day = CarbonImmutable::today()->addDays(3);
    $main = fn (int $seed): string => KeywordPicker::pick($this->workspace, new PostContext(day: $day, seed: $seed))[0]['text'];

    expect([$main(0), $main(1), $main(2), $main(3)])->toBe(['payroll software nepal', 'hr software nepal', 'attendance system nepal', 'payroll software nepal'])
        // A post about one of them gets that one, whatever its turn.
        ->and(KeywordPicker::pick($this->workspace, new PostContext(day: $day, focus: 'Attendance tips', seed: 0))[0]['text'])->toBe('attendance system nepal');
});

test('an item shows its keywords; a person changes them for that post and the copy writer gets only those', function () {
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.write' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);
    Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);
    BrandKeywords::save($this->workspace, [
        ['text' => 'payroll software nepal', 'main' => true],
        ['text' => 'office culture nepal', 'main' => false, 'tags' => [['kind' => 'pillar', 'value' => 'Culture']]],
        ['text' => 'hr team nepal', 'main' => false],
        ['text' => 'leave tracker', 'main' => false],
    ]);
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $item = PlanItem::factory()->create(['plan_id' => $plan->id, 'pillar' => 'Culture', 'title' => 'Friday team lunch', 'objective' => 'Show the team.']);

    $this->actingAs($this->owner)->get(route('socialhq.plan.items.show', $item->id))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('keywords.chosen', false)
            ->where('keywords.picked.0.text', 'payroll software nepal')
            ->where('keywords.picked.1.text', 'office culture nepal')
            ->where('keywords.picked.1.matched', [['kind' => 'pillar', 'label' => 'Culture']])
            ->has('keywords.catalogue', 4)
            ->where('keywords.max', 4));

    // Unknown keywords are dropped.
    $this->actingAs($this->owner)
        ->put(route('socialhq.plan.items.keywords.update', $item->id), ['keywords' => ['Leave Tracker', 'not in the catalogue']])
        ->assertRedirect();

    expect($item->fresh()->seo_keywords)->toBe(['leave tracker']);

    CopyWriter::fake([['caption' => 'Lunch with the team.', 'hashtags' => '', 'script' => '', 'notes' => '']]);
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $item->id))->assertCreated();

    CopyWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), "\"\"\"\n- leave tracker\n\"\"\"")
        && ! str_contains($prompt->agent->instructions(), 'payroll software nepal'));

    // Back to the app's pick.
    $this->actingAs($this->owner)->put(route('socialhq.plan.items.keywords.update', $item->id), ['keywords' => null])->assertRedirect();

    expect($item->fresh()->seo_keywords)->toBeNull()
        ->and(array_column(KeywordPicker::forItem($item->fresh()), 'text'))->toBe(['payroll software nepal', 'office culture nepal', 'hr team nepal']);
});

test('the composer shows a plan item\'s keywords, and the assistant writes with the ones the person left', function () {
    Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);
    BrandKeywords::save($this->workspace, [
        ['text' => 'payroll software nepal', 'main' => true],
        ['text' => 'hr team nepal', 'main' => false],
        ['text' => 'leave tracker', 'main' => false],
    ]);
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
    $item = PlanItem::factory()->create(['plan_id' => $plan->id, 'seo_keywords' => ['leave tracker']]);

    $this->actingAs($this->owner)
        ->getJson(route('socialhq.brand.keywords.for-post', ['plan_item_id' => $item->id]))
        ->assertOk()
        ->assertJsonPath('keywords.chosen', true)
        ->assertJsonPath('keywords.picked.*.text', ['leave tracker'])
        ->assertJsonPath('keywords.app_pick.0.text', 'payroll software nepal');

    // Another brand's item is not found.
    $elsewhere = PlanItem::factory()->create();
    $this->actingAs($this->owner)->getJson(route('socialhq.brand.keywords.for-post', ['plan_item_id' => $elsewhere->id]))->assertNotFound();

    PostWritingAssistant::fake(['Payroll done before Dashain.']);
    $this->actingAs($this->owner)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'Tell people payroll is easy', 'seo_keywords' => ['hr team nepal', 'unknown words']])
        ->assertOk();

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), "\"\"\"\n- hr team nepal\n\"\"\"")
        && ! str_contains($prompt->agent->instructions(), 'payroll software nepal'));

    // Without a choice the app picks for what the post is about.
    PostWritingAssistant::fake(['Payroll, sorted.']);
    $this->actingAs($this->owner)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'Tell people payroll is easy'])
        ->assertOk();

    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), '- payroll software nepal (main)'));
});

test('a keyword on the list for over 60 days that no post said lately is flagged', function () {
    BrandKeywords::save($this->workspace, [
        ['text' => 'payroll software', 'main' => true],
        ['text' => 'leave tracker', 'main' => false],
        ['text' => 'attendance app', 'main' => false],
    ]);
    Keyword::query()->whereIn('text', ['payroll software', 'leave tracker'])->update(['created_at' => now()->subDays(70)]);
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'content' => '<p>Our payroll software is ready.</p>', 'published_at' => now()->subDays(20)]);
    // Said 80 days ago: too long ago.
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'content' => '<p>Try the leave tracker.</p>', 'published_at' => now()->subDays(80), 'scheduled_at' => now()->subDays(80)]);

    $page = KeywordCheck::page($this->workspace);
    $stale = collect($page['keywords'])->mapWithKeys(fn (array $row): array => [$row['text'] => $row['stale']])->all();

    // attendance app is new: not flagged yet.
    expect($stale)->toBe(['payroll software' => false, 'leave tracker' => true, 'attendance app' => false])
        ->and($page['stale'])->toBe(['count' => 1, 'days' => 60]);
});
