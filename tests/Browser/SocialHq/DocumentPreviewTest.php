<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\Documents\DocumentSource;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\DocumentType;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\Website\CrawledPage;
use App\SocialHq\Knowledge\Website\CrawlStatus;
use App\SocialHq\Knowledge\Website\WebsiteCrawl;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Preview on Brand knowledge › Sources: a document is looked at inside the
 * app (Markdown rendered without its HTML, a PDF in the browser's viewer),
 * and the "Website text" suggestion is read, then accepted, from its
 * preview.
 */

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
});

/**
 * A workspace admin, signed in.
 *
 * @return array{0: User, 1: Workspace}
 */
function documentPreviewAdmin(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    test()->actingAs($user = $user->fresh());

    return [$user, $workspace];
}

function documentPreviewFile(Workspace $workspace, string $fileName, DocumentType $type, string $bytes): KnowledgeDocument
{
    $path = DocumentStorage::directory($workspace->id).'/'.Str::uuid()->toString().".{$type->value}";
    Storage::disk(DocumentStorage::DISK)->put($path, $bytes);

    return KnowledgeDocument::query()->create([
        'workspace_id' => $workspace->id,
        'title' => pathinfo($fileName, PATHINFO_FILENAME),
        'file_name' => $fileName,
        'mime' => $type->mime(),
        'size' => strlen($bytes),
        'path' => $path,
        'status' => DocumentStatus::Ready,
        'use_in_ai' => true,
    ]);
}

test('a Markdown document previews rendered without its HTML, and a PDF previews in a frame from the app', function () {
    [, $workspace] = documentPreviewAdmin();
    $prices = documentPreviewFile($workspace, 'prices.md', DocumentType::Markdown, "# Price list\n\nThe Trailblazer 40 costs Rs 7,500.\n\n<img src=\"x\" onerror=\"window.__previewRan = true\">\n");
    $brochure = documentPreviewFile($workspace, 'brochure.pdf', DocumentType::Pdf, (string) file_get_contents(base_path('tests/fixtures/socialhq/brochure.pdf')));

    $page = visit(route('socialhq.brand.index', ['tab' => 'sources']))->resize(1440, 900);

    // Markdown: rendered, with the file's HTML shown as text and never run.
    $page->click("@socialhq-document-preview-{$prices->id}");
    waitForText($page, '@socialhq-preview-markdown', 'The Trailblazer 40 costs Rs 7,500.');
    $page->assertSeeIn('@socialhq-preview-dialog', 'prices.md')
        ->assertSeeIn('@socialhq-preview-markdown', '<img src="x"');
    $html = $page->script("document.querySelector('[data-testid=\"socialhq-preview-markdown\"]').innerHTML");
    expect($html)->toContain('<h1>Price list</h1>')
        ->not->toContain('<img')
        ->and($page->script('window.__previewRan === undefined'))->toBeTrue();

    $page->click('@dialog-close');
    waitForGone($page, '@socialhq-preview-dialog');

    // PDF: the browser's own viewer, framed from the app's own address, inline.
    $view = route('socialhq.brand.documents.view', $brochure->id, false);
    $page->click("@socialhq-document-preview-{$brochure->id}");
    waitForState($page, '@socialhq-preview-pdf', "element.getAttribute('src').endsWith('{$view}')");
    waitForState($page, '@socialhq-preview-open-tab', "element.getAttribute('href').endsWith('{$view}') && element.getAttribute('rel') === 'noopener noreferrer'");
    $served = $page->script("fetch(document.querySelector('[data-testid=\"socialhq-preview-pdf\"]').src).then((response) => [response.status, response.headers.get('content-type'), response.headers.get('content-disposition')].join(' | '))");
    expect($served)->toStartWith('200 | application/pdf | inline');

    $page->assertNoJavaScriptErrors();
});

test('the Website text suggestion is read in its preview and accepted from there', function () {
    [$user, $workspace] = documentPreviewAdmin();

    foreach ([
        ['https://acme.example/', 'Acme Bags', 'Hand-stitched trekking bags from Kathmandu since 2009.'],
        ['https://acme.example/products', 'Products', 'The Trailblazer 40 is a waterproof bag that costs Rs 7,500.'],
    ] as [$url, $title, $text]) {
        CrawledPage::query()->create(['workspace_id' => $workspace->id, 'url' => $url, 'title' => $title, 'text' => $text, 'content_hash' => hash('sha256', $text)]);
    }

    $crawl = WebsiteCrawl::query()->create([
        'workspace_id' => $workspace->id,
        'url' => 'https://acme.example/',
        'status' => CrawlStatus::Ready,
        'pages_read' => 2,
        'requested_by' => $user->id,
        'finished_at' => now(),
        'suggestions' => [
            ['key' => 'document', 'type' => 'document', 'pages' => 2, 'status' => 'open'],
        ],
    ]);

    $page = visit(route('socialhq.brand.index', ['tab' => 'sources']))->resize(1440, 900);

    $page->click('@socialhq-website-suggestion-preview-document');
    waitForText($page, '@socialhq-preview-page-0', 'Hand-stitched trekking bags from Kathmandu since 2009.');
    $page->assertSeeIn('@socialhq-preview-page-0', 'https://acme.example/')
        ->assertSeeIn('@socialhq-preview-page-1', 'The Trailblazer 40 is a waterproof bag that costs Rs 7,500.');

    $page->click('@socialhq-preview-accept');
    waitForGone($page, '@socialhq-preview-dialog');
    waitForText($page, '@socialhq-website-reviewed-toggle', __('socialhq_knowledge.website.review.reviewed', ['count' => 1]));
    waitForText($page, '@socialhq-documents', 'Website (acme.example)');

    expect(collect($crawl->fresh()->suggestions)->firstWhere('key', 'document')['status'])->toBe('accepted')
        ->and(KnowledgeDocument::query()->where('workspace_id', $workspace->id)->where('source', DocumentSource::Website->value)->exists())->toBeTrue();

    $page->assertNoJavaScriptErrors();
});
