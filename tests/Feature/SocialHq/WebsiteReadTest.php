<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use App\SocialHq\Companies\Company;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Documents\DocumentSource;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\Keywords\BrandKeywords;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\Look\BrandLook;
use App\SocialHq\Knowledge\Website\CrawledPage;
use App\SocialHq\Knowledge\Website\CrawlFailure;
use App\SocialHq\Knowledge\Website\CrawlStatus;
use App\SocialHq\Knowledge\Website\WebsiteCrawl;
use App\SocialHq\Knowledge\Website\WebsiteKeywordReader;
use App\SocialHq\Knowledge\Website\WebsiteReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    Sleep::fake();
    fakePublicDns();
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.structured' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
        'socialhq.knowledge.website.delay_ms' => 500,
    ]);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    // AI runs for a company a platform admin allowed Social HQ's AI keys.
    Company::factory()->usingPlatformAi()->create(['account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    // The keyword step suggests nothing unless a test says otherwise.
    WebsiteKeywordReader::fake(fn (): array => ['keywords' => []]);
});

/**
 * A page of the test site: its main text inside the shared menu, footer and cookie banner.
 */
function sitePage(string $main, string $title = 'Acme Bags', string $head = '', string $links = ''): string
{
    return <<<HTML
        <!doctype html>
        <html><head><title>{$title}</title>{$head}<script>var tracking = "SECRET_SCRIPT";</script><style>.x{color:#000}</style></head>
        <body>
          <header><nav><a href="/">Home</a> <a href="/about">About us menu</a></nav></header>
          <div class="cookie-banner">We use cookies to improve your experience. Accept all</div>
          <main>{$main}<p>Order now on WhatsApp.</p>{$links}</main>
          <aside>Related articles sidebar</aside>
          <footer><p>© Acme Bags, Thamel, Kathmandu. All rights reserved.</p></footer>
        </body></html>
        HTML;
}

/**
 * The test site at acme.example, with robots.txt, a sitemap and four pages.
 *
 * @param  array<string, mixed>  $extra  Stubs that replace or add to the site's
 */
function fakeAcmeSite(array $extra = [], string $robots = "User-agent: *\nDisallow: /private\nSitemap: https://acme.example/sitemap.xml"): void
{
    $html = ['Content-Type' => 'text/html; charset=utf-8'];
    $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .'<url><loc>https://acme.example/about</loc></url>'
        .'<url><loc>https://acme.example/private/staff</loc></url>'
        .'<url><loc>https://acme.example/products</loc></url>'
        .'<url><loc>https://elsewhere.example/page</loc></url>'
        .'<url><loc>https://acme.example/brochure.pdf</loc></url>'
        .'</urlset>';

    // The extra stubs replace the defaults of the same address; anything else is a 404.
    Http::fake([...array_merge([
        'acme.example/robots.txt' => Http::response($robots, 200, ['Content-Type' => 'text/plain']),
        'acme.example/sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
        'acme.example/about' => Http::response(sitePage('<h1>About us</h1><p>Acme Bags has hand-stitched trekking bags in Kathmandu since 2009, for hikers and guides.</p>', 'About Acme'), 200, $html),
        'acme.example/products' => Http::response(sitePage('<h1>Products</h1><p>The Trailblazer 40 is a waterproof trekking bag that costs Rs 7,500.</p>', 'Products'), 200, ['ETag' => '"products-v1"', ...$html]),
        'acme.example/contact' => Http::response(sitePage('<h1>Contact</h1><p>Visit our shop in Thamel, open daily from ten in the morning to eight at night.</p>', 'Contact'), 200, $html),
        'acme.example/' => Http::response(sitePage(
            '<h1>Bags for every trail</h1><p>Friendly, hand-made trekking gear for Nepal\'s mountains and the people who climb them.</p>',
            'Acme Bags',
            '<meta name="theme-color" content="#0B1F3A"><style>.btn{background:#F5A623}.btn:hover{background:#f5a623}</style><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600&family=Inter&display=swap" rel="stylesheet">',
            '<a href="/contact">Contact</a> <a href="https://elsewhere.example/partner">Partner</a> <a href="mailto:hi@acme.example">Mail</a>',
        ), 200, $html),
    ], $extra), '*' => Http::response('Not found', 404)]);
}

function websiteSuggestionAnswer(array $overrides = []): array
{
    return [
        'profile' => ['about' => 'Acme Bags hand-stitches trekking bags in Kathmandu since 2009.', 'audience' => 'Hikers and trekking guides.', 'voice' => ''],
        'entries' => [
            ['kind' => 'product', 'title' => 'Trailblazer 40', 'body' => 'A waterproof trekking bag, Rs 7,500.'],
            ['kind' => 'fact', 'title' => 'Shop hours', 'body' => 'Thamel shop, open daily from 10 to 8.'],
        ],
        'theme' => [
            'colours' => [['name' => 'Navy', 'hex' => '#0b1f3a'], ['name' => 'Marigold', 'hex' => '#f5a623'], ['name' => 'Invented', 'hex' => '#123456']],
            'heading_font' => 'Poppins',
            'body_font' => 'Inter',
            'style_notes' => '',
        ],
        ...$overrides,
    ];
}

function readWebsite(User $user, string $url = 'acme.example'): TestResponse
{
    return test()->actingAs($user)->post(route('socialhq.brand.website.store'), ['url' => $url]);
}

function suggestionByKey(WebsiteCrawl $crawl, string $key): array
{
    return collect($crawl->fresh()->suggestions)->firstWhere('key', $key);
}

test('reading the website sends only the cleaned, deduplicated main text of allowed same-site pages to the AI', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);

    readWebsite($this->owner)->assertSessionHasNoErrors()->assertRedirect();

    $crawl = WebsiteCrawl::query()->sole();
    expect($crawl->status)->toBe(CrawlStatus::Ready)
        ->and($crawl->url)->toBe('https://acme.example/')
        ->and($crawl->pages_read)->toBe(4)
        ->and($crawl->pages_skipped)->toBe(1)
        ->and($crawl->provider)->not->toBeNull();

    // robots.txt, the sitemap and the four pages: never the disallowed page, another site or a PDF.
    Http::assertSentCount(6);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/private')
        || str_contains($request->url(), 'elsewhere.example')
        || str_contains($request->url(), 'brochure.pdf'));
    Sleep::assertSleptTimes(5);

    WebsiteReader::assertPrompted(function (AgentPrompt $prompt): bool {
        $sent = json_decode($prompt->prompt, true);
        $text = collect($sent['pages'])->pluck('text')->implode("\n");

        return $sent['pages'][0]['url'] === 'https://acme.example/'
            && str_contains($text, 'hand-stitched trekking bags in Kathmandu since 2009')
            && str_contains($text, 'costs Rs 7,500')
            && substr_count($text, 'Order now on WhatsApp.') === 1
            && ! str_contains($text, 'SECRET_SCRIPT')
            && ! str_contains($text, 'cookies')
            && ! str_contains($text, 'About us menu')
            && ! str_contains($text, 'All rights reserved')
            && ! str_contains($text, 'sidebar')
            && $sent['colours_found'][0] === '#0b1f3a'
            && in_array('#f5a623', $sent['colours_found'], true)
            && in_array('Poppins', $sent['fonts_found'], true);
    });

    // Suggestions only: nothing is saved before an admin accepts it.
    expect(BrandProfile::query()->count())->toBe(0)
        ->and(KnowledgeEntry::query()->count())->toBe(0)
        ->and(BrandLook::query()->count())->toBe(0)
        ->and(collect($crawl->suggestions)->pluck('key')->all())->toBe([
            'profile-about', 'profile-audience', 'entry-1', 'entry-2', 'colour-1', 'colour-2',
            'font-heading_font', 'font-body_font', 'document',
        ]);
});

test('an admin accepts, edits or skips each suggestion, once', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    readWebsite($this->owner);
    $crawl = WebsiteCrawl::query()->sole();
    $accept = fn (string $key, array $data = []): TestResponse => $this->post(route('socialhq.brand.website.accept', [$crawl->id, $key]), $data);

    $accept('profile-about', ['value' => 'Acme Bags makes trekking bags by hand in Kathmandu.'])->assertSessionHasNoErrors();
    $accept('entry-1')->assertSessionHasNoErrors();
    // An edited colour may be typed without "#".
    $accept('colour-1', ['name' => 'Midnight', 'hex' => '0B1F3A'])->assertSessionHasNoErrors();
    $accept('font-heading_font')->assertSessionHasNoErrors();
    $this->post(route('socialhq.brand.website.skip', [$crawl->id, 'entry-2']))->assertSessionHasNoErrors();
    $accept('document')->assertSessionHasNoErrors();

    expect(BrandProfile::query()->sole()->about)->toBe('Acme Bags makes trekking bags by hand in Kathmandu.')
        ->and(BrandProfile::query()->sole()->audience)->toBeNull()
        ->and(KnowledgeEntry::query()->pluck('title')->all())->toBe(['Trailblazer 40'])
        ->and(BrandLook::of($this->workspace)->colourList())->toBe([['name' => 'Midnight', 'hex' => '#0b1f3a']])
        ->and(BrandLook::of($this->workspace)->heading_font)->toBe('Poppins')
        ->and(suggestionByKey($crawl, 'entry-2')['status'])->toBe('skipped')
        ->and(suggestionByKey($crawl, 'colour-1')['name'])->toBe('Midnight');

    $website = KnowledgeDocument::query()->where('source', DocumentSource::Website->value)->sole();
    expect($website->status)->toBe(DocumentStatus::Ready)
        ->and($website->title)->toBe('Website (acme.example)')
        ->and($website->chunks()->pluck('text')->implode(' '))->toContain('Trailblazer 40');

    $accept('entry-1')->assertSessionHasErrors('suggestion');
    $this->post(route('socialhq.brand.website.skip', [$crawl->id, 'profile-about']))->assertSessionHasErrors('suggestion');
    $accept('nothing-here')->assertNotFound();
    expect(KnowledgeEntry::query()->count())->toBe(1);
});

test('an edited value must fit the field it goes into', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    readWebsite($this->owner);
    $crawl = WebsiteCrawl::query()->sole();
    $accept = fn (string $key, string $value): TestResponse => $this->post(route('socialhq.brand.website.accept', [$crawl->id, $key]), ['value' => $value]);

    $accept('font-heading_font', str_repeat('P', 81))->assertSessionHasErrors(['value' => 'The Heading font field must not be greater than 80 characters.']);
    $accept('profile-audience', str_repeat('a', 1001))->assertSessionHasErrors('value');

    expect(BrandLook::of($this->workspace))->toBeNull()
        ->and(BrandProfile::query()->exists())->toBeFalse()
        ->and(suggestionByKey($crawl, 'font-heading_font')['status'])->toBe('open');

    $accept('font-heading_font', str_repeat('P', 80))->assertSessionHasNoErrors();
    expect(BrandLook::of($this->workspace)->heading_font)->toBe(str_repeat('P', 80));
});

test('keeping the website\'s text leaves no file behind when the decision fails', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    readWebsite($this->owner);
    $crawl = WebsiteCrawl::query()->sole();
    WebsiteCrawl::updating(fn () => throw new RuntimeException('database down'));

    $this->post(route('socialhq.brand.website.accept', [$crawl->id, 'document']))->assertServerError();

    expect(KnowledgeDocument::query()->count())->toBe(0)
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
});

test('a site that hasn\'t changed is not read into the AI again', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer(), websiteSuggestionAnswer()]);

    readWebsite($this->owner);
    $first = WebsiteCrawl::query()->sole();
    $this->post(route('socialhq.brand.website.skip', [$first->id, 'entry-1']));

    readWebsite($this->owner);
    $second = WebsiteCrawl::query()->latest('id')->first();

    // Same text, same hash: no AI call (no provider recorded), the last suggestions as they were decided.
    expect($second->status)->toBe(CrawlStatus::Unchanged)
        ->and($second->pages_unchanged)->toBe(4)
        ->and($second->pages_read)->toBe(0)
        ->and($second->provider)->toBeNull()
        ->and($second->input_hash)->toBe($first->fresh()->input_hash)
        ->and(suggestionByKey($second, 'entry-1')['status'])->toBe('skipped');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://acme.example/products' && $request->header('If-None-Match') === ['"products-v1"']);
});

test('text over the budget is cut before the AI call', function () {
    config(['socialhq.knowledge.website.page_characters' => 500, 'socialhq.knowledge.website.ai_characters' => 2000]);
    $long = '<p>'.implode('</p><p>', array_map(fn (int $line): string => "Trekking fact number {$line} about our hand-made bags and their waterproof seams.", range(1, 200))).'</p>';
    fakeAcmeSite(['acme.example/' => Http::response(sitePage($long), 200, ['Content-Type' => 'text/html'])]);
    WebsiteReader::fake([websiteSuggestionAnswer()]);

    readWebsite($this->owner);

    WebsiteReader::assertPrompted(function (AgentPrompt $prompt): bool {
        $pages = json_decode($prompt->prompt, true)['pages'];
        $total = array_sum(array_map(fn (array $page): int => mb_strlen($page['text']) + mb_strlen($page['url']) + mb_strlen($page['title']), $pages));

        return mb_strlen($pages[0]['text']) <= 500 && $total <= 2000;
    });
    expect(WebsiteCrawl::query()->sole()->characters)->toBeLessThanOrEqual(2000);
});

test('a site on a private address, or redirecting to one, is not read', function () {
    $this->mock(HostResolver::class)->shouldReceive('addresses')->andReturn(['10.0.0.7']);
    Http::fake();

    readWebsite($this->owner, 'intranet.example')->assertSessionHasNoErrors();

    expect(WebsiteCrawl::query()->sole()->failure)->toBe(CrawlFailure::PrivateNetwork);
    Http::assertNothingSent();
});

test('redirects to another site or to a loopback address are not followed', function () {
    fakeAcmeSite([
        'acme.example/about' => Http::response('', 301, ['Location' => 'http://127.0.0.1/admin']),
        'acme.example/products' => Http::response('', 302, ['Location' => 'https://elsewhere.example/products']),
        'acme.example/contact' => Http::response('', 301, ['Location' => 'https://www.acme.example/contact-us']),
        'www.acme.example/contact-us' => Http::response(sitePage('<p>Visit our shop in Thamel, open daily from ten in the morning to eight at night.</p>'), 200, ['Content-Type' => 'text/html']),
    ]);
    WebsiteReader::fake([websiteSuggestionAnswer()]);

    readWebsite($this->owner);

    expect(WebsiteCrawl::query()->sole()->status)->toBe(CrawlStatus::Ready);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1') || str_contains($request->url(), 'elsewhere.example'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.acme.example/contact-us');
});

test('a redirect to another port of the site, or a page compressed anyway, is not read', function () {
    fakeAcmeSite([
        'acme.example/about' => Http::response('', 301, ['Location' => 'https://acme.example:8443/about']),
        'acme.example/products' => Http::response(gzencode(sitePage('<p>The Trailblazer 40 is a waterproof trekking bag that costs Rs 7,500.</p>')), 200, ['Content-Type' => 'text/html', 'Content-Encoding' => 'gzip']),
    ]);
    WebsiteReader::fake([websiteSuggestionAnswer()]);

    readWebsite($this->owner);

    expect(WebsiteCrawl::query()->sole()->status)->toBe(CrawlStatus::Ready)
        ->and(CrawledPage::query()->pluck('url')->all())->not->toContain('https://acme.example/products');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), ':8443'));
});

test('a site whose robots.txt disallows everything is not read', function () {
    fakeAcmeSite(robots: "User-agent: SocialHQBot\nDisallow: /\n\nUser-agent: *\nAllow: /");
    WebsiteReader::fake();

    readWebsite($this->owner);

    expect(WebsiteCrawl::query()->sole()->failure)->toBe(CrawlFailure::Robots);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://acme.example/');
    WebsiteReader::assertNeverPrompted();
});

test('only workspace admins read the website and decide, and another workspace\'s run is not found', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    readWebsite($this->owner);
    $crawl = WebsiteCrawl::query()->sole();

    $member = workspaceMember($this->workspace);
    readWebsite($member)->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.brand.website.accept', [$crawl->id, 'entry-1']))->assertForbidden();
    $this->get(route('socialhq.brand.index'))->assertOk()->assertInertia(fn ($page) => $page->where('website.crawl.suggestions.0.key', 'profile-about'));

    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $otherWorkspace->members()->attach($other->id, membershipPivot('admin'));
    $other->update(['current_workspace_id' => $otherWorkspace->id]);

    $this->actingAs($other->fresh())->post(route('socialhq.brand.website.accept', [$crawl->id, 'entry-1']))->assertNotFound();
    expect(KnowledgeEntry::query()->count())->toBe(0);
});

test('addresses that aren\'t a public website are refused before anything is read', function (string $url) {
    Http::fake();

    readWebsite($this->owner, $url)->assertSessionHasErrors('url');

    expect(WebsiteCrawl::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'ftp' => ['ftp://acme.example'],
    'a bare name' => ['localhost'],
    'a port' => ['https://acme.example:8080'],
    'credentials' => ['https://me:secret@acme.example'],
]);

/**
 * One keyword of WebsiteKeywordReader's answer.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function websiteKeyword(string $text, array $overrides = []): array
{
    return ['text' => $text, 'main' => false, 'group' => 'features', 'source' => '', 'pillar' => '', 'product' => '', 'occasion' => '', 'months_from' => 0, 'months_to' => 0, 'sample' => '', ...$overrides];
}

/**
 * @return list<array<string, mixed>>
 */
function keywordSuggestions(WebsiteCrawl $crawl): array
{
    return array_values(array_filter((array) $crawl->fresh()->suggestions, fn (array $suggestion): bool => $suggestion['type'] === 'keyword'));
}

test('reading the website also suggests SEO keywords from what its pages stress, checked and pre-tagged', function () {
    $this->workspace->update(['name' => 'Acme Bags']);
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'pillars' => ['Gear guides']]);
    KnowledgeEntry::query()->create(['workspace_id' => $this->workspace->id, 'kind' => 'product', 'title' => 'Trailblazer 40', 'body' => 'A waterproof trekking bag.', 'use_in_ai' => true]);
    CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'name' => 'Dashain', 'key' => 'dashain', 'starts_on' => now()->addDays(20)->toDateString(), 'ends_on' => now()->addDays(20)->toDateString()]);
    // Four main keywords already: one main slot is free.
    BrandKeywords::save($this->workspace, array_map(fn (string $text): array => ['text' => $text, 'main' => true], ['trekking bags kathmandu', 'hiking backpacks', 'guide packs', 'travel duffels']));

    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    WebsiteKeywordReader::fake([['keywords' => [
        websiteKeyword('Waterproof Trekking Bag', ['main' => true, 'source' => 'waterproof trekking bag', 'product' => 'trailblazer 40', 'pillar' => 'gear guides', 'sample' => 'A waterproof trekking bag for the monsoon trail.']),
        // Main is full by now: it is offered for the library.
        websiteKeyword('hand stitched bags', ['main' => true, 'source' => 'hand-stitched trekking bags', 'group' => 'audience', 'sample' => 'Our bags are sewn by hand.']),
        websiteKeyword('trekking gear nepal', ['group' => 'seasonal', 'occasion' => 'Dashain', 'months_from' => 10, 'months_to' => 11, 'pillar' => 'Not a pillar']),
        // The brand's name, a claim, a price, one the catalogue has, one no page says, and a sentence.
        websiteKeyword('acme bags thamel'),
        websiteKeyword('best trekking bag'),
        websiteKeyword('trailblazer rs 7500'),
        websiteKeyword('Trekking Bags in Kathmandu'),
        websiteKeyword('everest base camp gear'),
        websiteKeyword('where can i buy a trekking bag'),
    ]]]);

    readWebsite($this->owner)->assertSessionHasNoErrors();
    $crawl = WebsiteCrawl::query()->sole();
    $keywords = keywordSuggestions($crawl);

    expect($crawl->status)->toBe(CrawlStatus::Ready)
        ->and(array_column($keywords, 'key'))->toBe(['keyword-1', 'keyword-2', 'keyword-3'])
        ->and(array_column($keywords, 'text'))->toBe(['waterproof trekking bag', 'hand stitched bags', 'trekking gear nepal'])
        ->and(array_column($keywords, 'main'))->toBe([true, false, false])
        ->and(array_column($keywords, 'main_full'))->toBe([false, true, false])
        ->and(array_column($keywords, 'group'))->toBe(['features', 'audience', 'seasonal'])
        ->and(collect($keywords[0]['tags'])->map(fn (array $tag): array => [$tag['kind'], $tag['label']])->all())->toBe([['pillar', 'Gear guides'], ['product', 'Trailblazer 40']])
        // An unknown pillar is dropped; the occasion is found by its name.
        ->and(collect($keywords[2]['tags'])->map(fn (array $tag): string => "{$tag['kind']}:{$tag['label']}")->all())->toBe(['event:Dashain', 'months:October to November'])
        ->and($keywords[0]['pages'])->toBe(1)
        ->and($keywords[0]['page_titles'])->toBe(['Products'])
        ->and($keywords[1]['page_titles'])->toBe(['About Acme'])
        ->and($keywords[0]['sample'])->toBe('A waterproof trekking bag for the monsoon trail.')
        // A caption line that doesn't say the keyword is left out.
        ->and($keywords[1]['sample'])->toBe('')
        ->and($keywords[0]['verdict'])->toBeIn(['good', 'check', 'weak', 'conflict'])
        ->and(BrandKeywords::forWorkspace($this->workspace))->toHaveCount(4);

    WebsiteKeywordReader::assertPrompted(function (AgentPrompt $prompt): bool {
        $sent = json_decode($prompt->prompt, true);

        return $sent['brand']['name'] === 'Acme Bags'
            && collect($sent['pages'])->pluck('title')->contains('Products')
            && collect($sent['pages'])->flatMap(fn (array $page): array => $page['headings'])->contains('About us')
            && in_array('trekking bags kathmandu', $sent['team_has']['keywords'], true)
            && $sent['team_has']['products'] === ['Trailblazer 40']
            && $sent['team_has']['occasions'][0]['key'] === 'dashain'
            && str_contains($prompt->agent->instructions(), 'main: true for at most 1 ');
    });
});

test('accepting a keyword suggestion adds it to the catalogue with its tags; main never takes another\'s place', function () {
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'pillars' => ['Gear guides']]);
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    WebsiteKeywordReader::fake([['keywords' => [
        websiteKeyword('waterproof trekking bag', ['main' => true, 'pillar' => 'Gear guides']),
        websiteKeyword('hand made trekking gear', ['main' => true, 'source' => 'hand-made trekking gear']),
        websiteKeyword('trekking gear nepal', ['months_from' => 10, 'months_to' => 11]),
    ]]]);
    readWebsite($this->owner);
    $crawl = WebsiteCrawl::query()->sole();
    $accept = fn (string $key, array $data = []): TestResponse => $this->post(route('socialhq.brand.website.accept', [$crawl->id, $key]), $data);

    $accept('keyword-1')->assertSessionHasNoErrors();
    // Main filled up after the read: the second goes to the library, edited.
    BrandKeywords::save($this->workspace, [
        ...BrandKeywords::catalogue($this->workspace),
        ...array_map(fn (string $text): array => ['text' => $text, 'main' => true], ['one main', 'two main', 'three main', 'four main']),
    ]);
    $accept('keyword-2', ['text' => 'Hand-made Trekking Gear Nepal'])->assertSessionHasNoErrors();
    $this->post(route('socialhq.brand.website.skip', [$crawl->id, 'keyword-3']))->assertSessionHasNoErrors();

    $catalogue = collect(BrandKeywords::catalogue($this->workspace))->keyBy('text');

    expect($catalogue['waterproof trekking bag']['main'])->toBeTrue()
        ->and(collect($catalogue['waterproof trekking bag']['tags'])->pluck('value')->all())->toBe(['Gear guides'])
        ->and($catalogue['hand-made trekking gear nepal']['main'])->toBeFalse()
        ->and($catalogue)->not->toHaveKey('trekking gear nepal')
        ->and(suggestionByKey($crawl, 'keyword-2')['status'])->toBe('accepted')
        ->and(suggestionByKey($crawl, 'keyword-2')['text'])->toBe('hand-made trekking gear nepal')
        ->and(suggestionByKey($crawl, 'keyword-3')['status'])->toBe('skipped')
        ->and(collect(BrandKeywords::forWorkspace($this->workspace))->where('main', true)->count())->toBe(5);
});

test('a keyword that is already in the catalogue is not added twice', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    WebsiteKeywordReader::fake([['keywords' => [websiteKeyword('waterproof trekking bag')]]]);
    readWebsite($this->owner);
    $crawl = WebsiteCrawl::query()->sole();
    BrandKeywords::save($this->workspace, [['text' => 'Waterproof trekking bag', 'main' => false]]);

    $this->post(route('socialhq.brand.website.accept', [$crawl->id, 'keyword-1']))
        ->assertSessionHasErrors('text');

    expect(BrandKeywords::forWorkspace($this->workspace))->toHaveCount(1)
        ->and(suggestionByKey($crawl, 'keyword-1')['status'])->toBe('open');
});

test('a keyword step that fails leaves the other suggestions, and an unchanged site gets keywords once', function () {
    fakeAcmeSite();
    WebsiteReader::fake([websiteSuggestionAnswer()]);
    WebsiteKeywordReader::fake(fn (): never => throw new RuntimeException('The keyword step failed.'));

    readWebsite($this->owner);
    $first = WebsiteCrawl::query()->sole();

    expect($first->status)->toBe(CrawlStatus::Ready)
        ->and(keywordSuggestions($first))->toBe([])
        ->and(collect($first->suggestions)->pluck('key'))->toContain('profile-about', 'document');

    // The same text again: no new read of the site's text, but the keyword step it missed runs.
    WebsiteKeywordReader::fake([['keywords' => [websiteKeyword('waterproof trekking bag')]]]);
    readWebsite($this->owner);
    $second = WebsiteCrawl::query()->latest('id')->first();

    expect($second->status)->toBe(CrawlStatus::Unchanged)
        ->and(array_column(keywordSuggestions($second), 'text'))->toBe(['waterproof trekking bag']);
    WebsiteReader::assertPrompted(fn (): bool => true);
});
