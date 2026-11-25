<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\User\Locale;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Companies\Company;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\KnowledgeChunk;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\Keywords\KeywordCheck;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Plan\Ai\CopyWriter;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
});

/**
 * @param  list<array{0: string, 1: bool}|string>  $keywords  [text, main] pairs, or texts (supporting)
 */
function brandWithKeywords(Workspace $workspace, array $keywords, array $profile = []): BrandProfile
{
    return BrandProfile::query()->updateOrCreate(['workspace_id' => $workspace->id], [
        'keywords' => array_map(fn (array|string $keyword): array => is_string($keyword)
            ? ['text' => $keyword, 'main' => false]
            : ['text' => $keyword[0], 'main' => $keyword[1]], $keywords),
        ...$profile,
    ]);
}

function keywordEntry(Workspace $workspace, string $title, string $body): KnowledgeEntry
{
    return KnowledgeEntry::query()->create(['workspace_id' => $workspace->id, 'kind' => 'product', 'title' => $title, 'body' => $body, 'use_in_ai' => true]);
}

function keywordDocument(Workspace $workspace, string $title, string $text): KnowledgeDocument
{
    $document = KnowledgeDocument::query()->create([
        'workspace_id' => $workspace->id,
        'title' => $title,
        'file_name' => "{$title}.txt",
        'mime' => 'text/plain; charset=UTF-8',
        'size' => 100,
        'path' => DocumentStorage::directory($workspace->id).'/'.Str::uuid().'.txt',
        'status' => DocumentStatus::Ready,
        'use_in_ai' => true,
        'chunk_count' => 1,
    ]);
    KnowledgeChunk::query()->create(['document_id' => $document->id, 'workspace_id' => $workspace->id, 'position' => 0, 'text' => $text]);

    return $document;
}

/**
 * @return array<string, array<string, mixed>> The check's rows by keyword
 */
function keywordVerdicts(Workspace $workspace): array
{
    return collect(KeywordCheck::page($workspace)['keywords'])->keyBy('text')->all();
}

test('an admin saves the keywords: cleaned, duplicates dropped, in their order', function () {
    $this->actingAs($this->owner)
        ->put(route('socialhq.brand.keywords.update'), ['keywords' => [
            ['text' => '  #Cold   Brew  ', 'main' => true],
            ['text' => 'nepali coffee', 'main' => false],
            ['text' => 'COLD BREW', 'main' => false],
            ['text' => 'Café Thamel', 'main' => false],
            ['text' => 'cafe thamel', 'main' => true],
        ]])
        ->assertRedirect();

    expect(BrandKeywords::forWorkspace($this->workspace))->toBe([
        ['text' => 'cold brew', 'main' => true],
        ['text' => 'nepali coffee', 'main' => false],
        ['text' => 'café thamel', 'main' => false],
    ]);

    $this->actingAs($this->owner)->get(route('socialhq.brand.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('keywords.items.0.text', 'cold brew')
            ->where('keywords.limits', ['main' => 5, 'supporting' => 25, 'prompt' => 8])
            ->missing('keywordCheck')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->has('keywordCheck.keywords', 3)
                ->where('keywordCheck.prompt.0.text', 'cold brew')));
});

test('members read the keywords but cannot change them', function () {
    brandWithKeywords($this->workspace, [['cold brew', true]]);
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->get(route('socialhq.brand.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', false)->has('keywords.items', 1));

    $this->actingAs($member)
        ->put(route('socialhq.brand.keywords.update'), ['keywords' => []])
        ->assertForbidden();
    $this->actingAs($member)
        ->postJson(route('socialhq.brand.keywords.check'), ['keywords' => ['cold brew']])
        ->assertForbidden();

    expect(BrandKeywords::forWorkspace($this->workspace))->toHaveCount(1);
});

test('the lists stop at 5 main and 30 in all, and a keyword is 2 to 60 characters', function (array $keywords, string $error) {
    $this->actingAs($this->owner)
        ->from(route('socialhq.brand.index'))
        ->put(route('socialhq.brand.keywords.update'), ['keywords' => $keywords])
        ->assertSessionHasErrors($error);

    expect(BrandKeywords::forWorkspace($this->workspace))->toBe([]);
})->with([
    'six main' => [array_map(fn (int $i): array => ['text' => "main keyword {$i}", 'main' => true], range(1, 6)), 'keywords'],
    'thirty-one' => [array_map(fn (int $i): array => ['text' => "keyword {$i}", 'main' => false], range(1, 31)), 'keywords'],
    'too short' => [[['text' => 'x', 'main' => false]], 'keywords.0.text'],
    'too long' => [[['text' => str_repeat('a', 61), 'main' => false]], 'keywords.0.text'],
]);

test('an admin can remove every keyword', function () {
    brandWithKeywords($this->workspace, [['cold brew', true]]);

    $this->actingAs($this->owner)
        ->put(route('socialhq.brand.keywords.update'), ['keywords' => []])
        ->assertRedirect();

    expect(BrandKeywords::isEmpty($this->workspace))->toBeTrue();
});

test('a word to avoid in a keyword is a conflict, and the keyword still goes to the AI', function () {
    brandWithKeywords($this->workspace, [['cheap coffee', true]], ['words_to_avoid' => 'Cheap, discount', 'about' => 'Coffee roasters.']);

    $row = keywordVerdicts($this->workspace)['cheap coffee'];

    expect($row['verdict'])->toBe('conflict')
        ->and($row['reason'])->toContain('“cheap” is in your words to avoid')
        ->and(BrandKeywords::forPrompt($this->workspace))->toBe([['text' => 'cheap coffee', 'main' => true]])
        ->and(KeywordCheck::page($this->workspace)['verdict'])->toBe('needs_look');
});

test('a keyword no single place backs up is weak, and the reason names the missing words', function () {
    brandWithKeywords($this->workspace, ['coffee delivery lalitpur', 'organic tea', 'coffee kathmandu'], ['about' => 'Specialty coffee roasted in Thamel.']);
    keywordEntry($this->workspace, 'Our roastery', 'We roast in Kathmandu twice a week.');

    $rows = keywordVerdicts($this->workspace);

    expect($rows['coffee delivery lalitpur']['verdict'])->toBe('weak')
        ->and($rows['coffee delivery lalitpur']['reason'])->toBe('Your brand knowledge mentions “coffee” but never “delivery” or “lalitpur”.')
        ->and($rows['organic tea']['reason'])->toBe('Nothing in your brand knowledge talks about it.')
        // Both words are there, but never in the same place.
        ->and($rows['coffee kathmandu']['verdict'])->toBe('weak')
        ->and($rows['coffee kathmandu']['reason'])->toBe('Your brand knowledge has every word of it, but never in one place.');
});

test('the check ignores accents: "cafe" is backed up by "café"', function () {
    brandWithKeywords($this->workspace, [['cafe in thamel', true]], ['about' => 'Our café is in Thamel.']);

    expect(keywordVerdicts($this->workspace)['cafe in thamel']['verdict'])->toBe('good');
});

test('broad, claim, foreign-place and twin keywords are to check', function () {
    brandWithKeywords(
        $this->workspace,
        ['coffee', 'best coffee in kathmandu', 'coffee london', 'kathmandu coffee', 'coffee kathmandu'],
        ['about' => 'Coffee in Kathmandu, with beans we also ship to London.', 'audience_locations' => [['country' => 'NP', 'city' => 'Kathmandu', 'timezone' => 'Asia/Kathmandu', 'share' => 100]]],
    );

    $rows = keywordVerdicts($this->workspace);

    expect($rows['coffee']['reason'])->toBe('Too broad: one word.')
        ->and($rows['best coffee in kathmandu']['reason'])->toContain('Reads as a claim (“best”)')
        ->and($rows['coffee london']['reason'])->toBe('London isn’t one of your audience places.')
        ->and($rows['kathmandu coffee']['reason'])->toBe('Almost the same as “coffee kathmandu”.')
        ->and(collect($rows)->pluck('verdict')->unique()->values()->all())->toBe(['check'])
        ->and(KeywordCheck::page($this->workspace)['verdict'])->toBe('mostly');
});

test('a town the time zones don\'t name is a place too: outside the audience unless its city or its whole country is', function () {
    $kathmandu = [['country' => 'NP', 'city' => 'Kathmandu', 'timezone' => 'Asia/Kathmandu', 'share' => 100]];
    brandWithKeywords(
        $this->workspace,
        ['coffee delivery lalitpur', 'cafe in thamel', 'momo in patan'],
        ['about' => 'Coffee delivery across Lalitpur, momo in Patan and our cafe in Thamel.', 'audience_locations' => $kathmandu],
    );

    $rows = keywordVerdicts($this->workspace);

    expect($rows['coffee delivery lalitpur']['verdict'])->toBe('check')
        ->and($rows['coffee delivery lalitpur']['reason'])->toBe('Lalitpur isn’t one of your audience places.')
        // Thamel is part of Kathmandu; Patan is part of Lalitpur, which isn't an audience place.
        ->and($rows['cafe in thamel']['verdict'])->toBe('good')
        ->and($rows['momo in patan']['reason'])->toBe('Patan isn’t one of your audience places.');

    // An audience in the whole of Nepal (no city) covers its towns.
    BrandProfile::query()->where('workspace_id', $this->workspace->id)->sole()
        ->update(['audience_locations' => [['country' => 'NP', 'city' => null, 'timezone' => 'Asia/Kathmandu', 'share' => 100]]]);
    Cache::flush();

    expect(collect(keywordVerdicts($this->workspace))->pluck('verdict')->unique()->values()->all())->toBe(['good']);
});

test('a good fit says where it was found, and counts the recent posts that say it', function () {
    brandWithKeywords($this->workspace, [['cold brew', true]], ['about' => 'A café in Thamel.']);
    keywordEntry($this->workspace, 'Cold brew bottles', 'Steeped for 18 hours, sold in 330 ml bottles.');
    keywordDocument($this->workspace, 'Summer menu', 'Our cold brew is on the summer menu.');
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'content' => '<p>Cold brew is back! #ColdBrew</p>']);
    Post::factory()->scheduled()->create(['workspace_id' => $this->workspace->id, 'content' => '<p>Iced latte season.</p>']);
    // A draft and an old post don't count.
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'content' => '<p>Cold brew draft</p>']);
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'content' => '<p>cold brew</p>', 'published_at' => now()->subDays(120), 'scheduled_at' => now()->subDays(120)]);

    $page = KeywordCheck::page($this->workspace);
    $row = $page['keywords'][0];

    expect($row['verdict'])->toBe('good')
        ->and($row['reason'])->toBe('Found in your entry “Cold brew bottles” and the document “Summer menu”.')
        ->and(collect($row['found'])->pluck('title')->all())->toBe(['Cold brew bottles', 'Summer menu'])
        ->and($row['found'][1]['snippet'])->toContain('cold brew is on the summer menu')
        ->and($row['posts'])->toBe(1)
        ->and($page['checked'])->toBe(['entries' => 1, 'documents' => 1, 'posts' => 2, 'days' => 90])
        ->and($page['verdict'])->toBe('on_brand');
});

test('reasons name what the check found, and fixes point to the brand\'s other keywords', function () {
    brandWithKeywords(
        $this->workspace,
        [['cafe in thamel', true], 'coffee', 'coffee london', 'best coffee in kathmandu', 'specialty coffee kathmandu', 'coffee delivery lalitpur', 'cold brew'],
        ['about' => 'Specialty coffee in Kathmandu, from our cafe in Thamel.', 'audience_locations' => [['country' => 'NP', 'city' => 'Kathmandu', 'timezone' => 'Asia/Kathmandu', 'share' => 100]]],
    );
    keywordEntry($this->workspace, 'Cold brew bottles', 'Our cold brew, in 330 ml bottles.');
    keywordEntry($this->workspace, 'Summer menu', 'Cold brew and iced lattes.');

    $rows = keywordVerdicts($this->workspace);

    expect($rows['cafe in thamel']['reason'])->toBe('Found in your profile. Thamel is in Kathmandu, one of your audience places.')
        ->and($rows['specialty coffee kathmandu']['reason'])->toBe('Found in your profile. Kathmandu is one of your audience places.')
        // Only entries hold it, so both are named (newest first, as the AI reads them).
        ->and($rows['cold brew']['reason'])->toBe('Found in your entries “Summer menu” and “Cold brew bottles”.')
        // The example skips "coffee london" (a place outside the audience) and the claim.
        ->and($rows['coffee']['fix'])->toBe('Pair it with what makes you different, like “specialty coffee kathmandu”.')
        ->and($rows['best coffee in kathmandu']['fix'])->toBe('“specialty coffee kathmandu” says the same without the claim.')
        ->and($rows['coffee delivery lalitpur']['verdict'])->toBe('weak')
        ->and($rows['coffee delivery lalitpur']['reason'])->toBe('Your brand knowledge mentions “coffee” but never “delivery” or “lalitpur”. Lalitpur isn’t one of your audience places.')
        ->and($rows['coffee delivery lalitpur']['fix'])->toBe('If it’s true, add an entry about “delivery” and “lalitpur”. The AI still uses it, only as a search phrase.')
        ->and($rows['coffee delivery lalitpur'])->not->toHaveKey('member_fix');
});

test('someone who can\'t edit Brand knowledge gets fixes that say who can, and the page sends them those', function () {
    brandWithKeywords($this->workspace, ['cheap coffee', 'coffee london'], ['about' => 'Coffee in Kathmandu, shipped to London.', 'words_to_avoid' => 'cheap', 'audience_locations' => [['country' => 'NP', 'city' => 'Kathmandu', 'timezone' => 'Asia/Kathmandu', 'share' => 100]]]);

    $rows = collect(KeywordCheck::page($this->workspace, canEdit: false)['keywords'])->keyBy('text');

    expect($rows['cheap coffee']['fix'])->toBe('The AI uses it as written. If that’s not what you want, a workspace admin can remove or reword it.')
        ->and($rows['coffee london']['fix'])->toBe('If you post for people there, a workspace admin can add it under Where your audience is.')
        ->and(keywordVerdicts($this->workspace)['cheap coffee']['fix'])->toStartWith('Your keyword wins');

    $this->actingAs(workspaceMember($this->workspace))
        ->get(route('socialhq.brand.index', ['tab' => 'strategy']))
        ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload
            ->where('keywordCheck.keywords.0.fix', 'The AI uses it as written. If that’s not what you want, a workspace admin can remove or reword it.')
            ->missing('keywordCheck.keywords.0.member_fix')));
});

test('a comma or semicolon never stays in a keyword: a quoted CSV cell is one keyword', function () {
    expect(BrandKeywords::clean('"Latte art, Kathmandu"'))->toBe('"latte art kathmandu"')
        ->and(BrandKeywords::clean('latte art; kathmandu'))->toBe('latte art kathmandu');
});

test('a file\'s keywords are checked before import, against the saved ones too', function () {
    brandWithKeywords($this->workspace, ['coffee kathmandu'], ['about' => 'Coffee in Kathmandu.', 'words_to_avoid' => 'cheap']);

    $this->actingAs($this->owner)
        ->postJson(route('socialhq.brand.keywords.check'), ['keywords' => ['Cheap coffee', 'kathmandu coffee']])
        ->assertOk()
        ->assertJsonPath('keywords.0.text', 'cheap coffee')
        ->assertJsonPath('keywords.0.verdict', 'conflict')
        ->assertJsonPath('keywords.1.verdict', 'check');

    expect(BrandKeywords::forWorkspace($this->workspace))->toHaveCount(1);
});

test('a prompt gets the main keywords first, then the supporting ones that match the topic, 8 at most', function () {
    $keywords = [
        ['text' => 'specialty coffee', 'main' => true],
        ...array_map(fn (int $i): array => ['text' => "supporting {$i}", 'main' => false], range(1, 8)),
        ['text' => 'cold brew bottles', 'main' => false],
        ['text' => 'nepali coffee', 'main' => true],
    ];

    expect(array_column(BrandKeywords::pick($keywords), 'text'))
        ->toBe(['specialty coffee', 'nepali coffee', 'supporting 1', 'supporting 2', 'supporting 3', 'supporting 4', 'supporting 5', 'supporting 6'])
        ->and(array_column(BrandKeywords::pick($keywords, 'A post about our cold brew bottles'), 'text'))
        ->toBe(['specialty coffee', 'nepali coffee', 'cold brew bottles', 'supporting 1', 'supporting 2', 'supporting 3', 'supporting 4', 'supporting 5']);
});

test('"Check what the AI reads" lists the keywords a topic gets', function () {
    brandWithKeywords($this->workspace, [['specialty coffee', true], 'cold brew']);

    $this->actingAs($this->owner)
        ->postJson(route('socialhq.brand.search'), ['focus' => 'cold brew in summer'])
        ->assertOk()
        ->assertJsonPath('keywords', [['text' => 'specialty coffee', 'main' => true], ['text' => 'cold brew', 'main' => false]]);
});

test('the caption assistant gets the keywords and the rules for new text, and only "keep them" for a rewrite', function () {
    brandWithKeywords($this->workspace, [['specialty coffee kathmandu', true]]);
    $this->actingAs($this->owner);

    $generate = (new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::DEFAULT))->instructions();
    $rephrase = (new PostWritingAssistant(PostAssistantMode::Rephrase, 'Our coffee.', Locale::DEFAULT))->instructions();
    $ideas = (new IdeaGenerator(Locale::DEFAULT, 'A café', 'Students'))->instructions();

    expect($generate)->toContain('- specialty coffee kathmandu (main)')->toContain('A keyword is a search phrase, not a fact')
        ->and($rephrase)->toContain('- specialty coffee kathmandu (main)')->toContain('Keep any of these keywords the existing text already has')
        ->not->toContain('A keyword is a search phrase')
        ->and($ideas)->toContain('- specialty coffee kathmandu (main)');

    BrandProfile::query()->update(['keywords' => null]);

    expect((new PostWritingAssistant(PostAssistantMode::Generate, '', Locale::DEFAULT))->instructions())->not->toContain("The brand's SEO keywords");
});

test('the assistant says when a new caption was written for a brand without keywords', function () {
    // AI runs for a company a platform admin allowed Social HQ's AI keys.
    Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);
    PostWritingAssistant::fake(['One', 'Two', 'Three']);

    $this->actingAs($this->owner)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'Introduce our new cold brew bottles'])
        ->assertOk()
        ->assertJsonPath('keywords_missing', true)
        ->assertJsonPath('can_add_keywords', true);

    // Someone who can't edit Brand knowledge gets the note without the link.
    $this->actingAs(workspaceMember($this->workspace))
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'Introduce our new cold brew bottles'])
        ->assertOk()
        ->assertJsonPath('keywords_missing', true)
        ->assertJsonPath('can_add_keywords', false);

    // A rewrite of the person's caption never shows the note.
    $this->actingAs($this->owner)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'rephrase', 'current_content' => 'Our cold brew is back.'])
        ->assertOk()
        ->assertJsonPath('keywords_missing', false);

    brandWithKeywords($this->workspace, ['cold brew']);

    $this->actingAs($this->owner)
        ->postJson(route('app.posts.ai.assist'), ['mode' => 'generate', 'prompt' => 'Introduce our new cold brew bottles'])
        ->assertOk()
        ->assertJsonPath('keywords_missing', false);
});

describe('plan item copy', function () {
    beforeEach(function () {
        config([
            'socialhq.ai.gateway' => 'direct',
            'socialhq.ai.approved_providers' => ['anthropic'],
            'socialhq.ai.routes.write' => 'anthropic',
            'ai.providers.anthropic.key' => 'test-anthropic-key',
        ]);
        // AI runs for a company a platform admin allowed Social HQ's AI keys.
        Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);

        $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id]);
        $this->item = PlanItem::factory()->create(['plan_id' => $plan->id, 'title' => 'Momo delivery week']);
    });

    test('the copy writer gets the keywords and their rules, and the answer says whether the brand has none', function () {
        CopyWriter::fake([['caption' => 'Momos at your door.', 'hashtags' => '#momo', 'script' => '', 'notes' => '']]);

        $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))
            ->assertCreated()
            ->assertJsonPath('keywords_missing', true);

        CopyWriter::assertPrompted(fn (AgentPrompt $prompt): bool => ! str_contains($prompt->agent->instructions(), "The brand's SEO keywords"));

        brandWithKeywords($this->workspace, [['momo delivery lalitpur', true]]);
        CopyWriter::fake([['caption' => 'Fresh momos now in Lalitpur.', 'hashtags' => '#momo', 'script' => '', 'notes' => '']]);

        $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $this->item->id))
            ->assertCreated()
            ->assertJsonPath('keywords_missing', false);

        CopyWriter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->agent->instructions(), '- momo delivery lalitpur (main)')
            && str_contains($prompt->agent->instructions(), 'when a main keyword fits the post, put it in the caption\'s first line')
            && str_contains($prompt->agent->instructions(), 'the brand knowledge or the SEO keywords'));

        // The place came from a keyword, so it isn't flagged as made up.
        expect(ItemVersion::query()->latest('number')->first()->unsupported_details)->toBeNull();
    });
});
