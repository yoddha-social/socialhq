<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\DocumentType;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\Website\CrawledPage;
use App\SocialHq\Knowledge\Website\CrawlStatus;
use App\SocialHq\Knowledge\Website\WebsiteCrawl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Preview on Brand knowledge: a document's file or text, and a "Read our
 * website" suggestion's pages, shown before anyone decides on them. Only
 * what the app already stored is shown; nothing is fetched.
 */

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
});

/**
 * A document of the workspace with its file on the private disk.
 */
function previewDocument(Workspace $workspace, string $fileName, DocumentType $type, string $bytes): KnowledgeDocument
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

/**
 * A Word file built in the test: a zip with its main part.
 */
function previewDocxBytes(string $paragraph): string
{
    $path = tempnam(sys_get_temp_dir(), 'shq-preview');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$paragraph.'</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * A finished read of the brand's site whose pages sit on private addresses:
 * a preview that fetched anything would have to reach them.
 */
function previewCrawl(Workspace $workspace, User $user, CrawlStatus $status = CrawlStatus::Ready): WebsiteCrawl
{
    foreach ([
        ['http://127.0.0.1/', 'Home', "Hand-stitched trekking bags from Kathmandu.\nOrder now on WhatsApp."],
        ['http://169.254.169.254/latest/meta-data', 'Products', "The Trailblazer 40 is a waterproof bag that costs Rs 7,500.\nOrder now on WhatsApp."],
    ] as [$url, $title, $text]) {
        CrawledPage::query()->create(['workspace_id' => $workspace->id, 'url' => $url, 'title' => $title, 'text' => $text, 'content_hash' => hash('sha256', $text)]);
    }

    return WebsiteCrawl::query()->create([
        'workspace_id' => $workspace->id,
        'url' => 'http://127.0.0.1/',
        'status' => $status,
        'pages_read' => 2,
        'requested_by' => $user->id,
        'suggestions' => [
            ['key' => 'entry-1', 'type' => 'entry', 'kind' => 'product', 'title' => 'Trailblazer 40', 'body' => 'A waterproof bag.', 'status' => 'open'],
            ['key' => 'document', 'type' => 'document', 'pages' => 2, 'status' => 'open'],
        ],
    ]);
}

test('members preview a text document, and see only its first 100 KB', function () {
    $menu = previewDocument($this->workspace, 'menu.txt', DocumentType::Text, "Momo and thukpa.\r\nOpen daily from ten.");
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->getJson(route('socialhq.brand.documents.preview', $menu->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJson(['kind' => 'text', 'text' => "Momo and thukpa.\nOpen daily from ten.", 'truncated' => false, 'kilobytes' => 100, 'file_url' => null]);

    // 150 KB of Devanagari (3 bytes a character): cut at 100 KB, never inside a character.
    $long = previewDocument($this->workspace, 'long.txt', DocumentType::Text, str_repeat('नमस्ते ', 8000));
    $preview = $this->getJson(route('socialhq.brand.documents.preview', $long->id))->assertOk()->json();

    expect($preview['truncated'])->toBeTrue()
        ->and(strlen($preview['text']))->toBeLessThanOrEqual(100 * 1024)->toBeGreaterThan(100 * 1024 - 4)
        ->and(mb_check_encoding($preview['text'], 'UTF-8'))->toBeTrue()
        ->and($preview['text'])->toStartWith('नमस्ते नमस्ते');
});

test('a Markdown file is rendered without its HTML, pictures, scripts or non-web links', function () {
    $markdown = <<<'MD'
        # Price list

        <script>alert('stolen')</script>

        <img src="x" onerror="alert(1)">

        Order on [our shop](https://shop.example/order), not [this](javascript:alert(1)) or [that](/socialhq/brand).

        ![Our logo](https://tracker.example/pixel.png)

        | Bag | Price |
        | --- | --- |
        | Trailblazer 40 | Rs 7,500 |
        MD;
    $document = previewDocument($this->workspace, 'prices.md', DocumentType::Markdown, $markdown);

    $preview = $this->actingAs($this->owner)->getJson(route('socialhq.brand.documents.preview', $document->id))->assertOk()->json();
    $html = $preview['html'];

    expect($preview['kind'])->toBe('markdown')
        ->and($preview['text'])->toBeNull()
        ->and($html)->toContain('<h1>Price list</h1>')
        ->and($html)->toContain('<td>Trailblazer 40</td>')
        // The file's own HTML is shown as text, never run.
        ->and($html)->toContain('&lt;script&gt;alert')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<img')
        // A picture never loads: its description stays as text.
        ->and($html)->toContain('Our logo')
        ->and($html)->not->toContain('tracker.example')
        // Web links open in a new tab, without a referrer or the app's window.
        ->and($html)->toContain('target="_blank" rel="noopener noreferrer nofollow" href="https://shop.example/order"')
        // Other links become their text.
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('href="/socialhq')
        ->and($html)->toContain('not this or that.');
});

test('a PDF is framed from the app\'s own origin, inline, as a PDF only', function () {
    $brochure = previewDocument($this->workspace, 'brochure.pdf', DocumentType::Pdf, (string) file_get_contents(base_path('tests/fixtures/socialhq/brochure.pdf')));
    $menu = previewDocument($this->workspace, 'menu.txt', DocumentType::Text, 'Momo and thukpa, open daily.');
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->getJson(route('socialhq.brand.documents.preview', $brochure->id))
        ->assertOk()
        ->assertJson(['kind' => 'pdf', 'text' => null, 'file_url' => route('socialhq.brand.documents.view', $brochure->id)]);

    $response = $this->get(route('socialhq.brand.documents.view', $brochure->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline; filename=brochure.pdf')
        ->and($response->streamedContent())->toStartWith('%PDF');

    // Any other type is never served inline.
    $this->get(route('socialhq.brand.documents.view', $menu->id))->assertNotFound();
});

test('a Word file shows the text read from it, and one that can\'t be read shows nothing', function () {
    $deck = previewDocument($this->workspace, 'about.docx', DocumentType::Docx, previewDocxBytes('Acme Bags has hand-stitched trekking bags in Kathmandu since 2009.'));
    $broken = previewDocument($this->workspace, 'broken.docx', DocumentType::Docx, 'not a zip at all');

    $this->actingAs($this->owner)->getJson(route('socialhq.brand.documents.preview', $deck->id))
        ->assertOk()
        ->assertJson(['kind' => 'extracted', 'text' => 'Acme Bags has hand-stitched trekking bags in Kathmandu since 2009.', 'truncated' => false]);

    $this->getJson(route('socialhq.brand.documents.preview', $broken->id))
        ->assertOk()
        ->assertJson(['kind' => 'none', 'text' => null, 'html' => null]);
});

test('only people who read the brand\'s knowledge preview its documents', function () {
    $document = previewDocument($this->workspace, 'menu.pdf', DocumentType::Pdf, (string) file_get_contents(base_path('tests/fixtures/socialhq/brochure.pdf')));

    $this->getJson(route('socialhq.brand.documents.preview', $document->id))->assertUnauthorized();
    $this->get(route('socialhq.brand.documents.view', $document->id))->assertRedirect(route('login'));

    // Someone of the same company who isn't a member of this brand.
    $outsider = User::factory()->create(['account_id' => $this->workspace->account_id, 'current_workspace_id' => $this->workspace->id]);
    $this->actingAs($outsider)->getJson(route('socialhq.brand.documents.preview', $document->id))->assertForbidden();
    $this->actingAs($outsider)->get(route('socialhq.brand.documents.view', $document->id))->assertForbidden();

    // Another brand's document isn't found, even by its admin.
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $theirs = previewDocument($other, 'secret.txt', DocumentType::Text, 'Their prices.');
    $this->actingAs($this->owner)->getJson(route('socialhq.brand.documents.preview', $theirs->id))->assertNotFound();
    $this->actingAs($this->owner)->get(route('socialhq.brand.documents.view', $theirs->id))->assertNotFound();
});

test('the Website text suggestion previews the stored pages as Accept keeps them, fetching nothing', function () {
    Http::preventStrayRequests();
    Http::fake();
    $crawl = previewCrawl($this->workspace, $this->owner);

    $this->actingAs($this->owner)->getJson(route('socialhq.brand.website.preview', [$crawl->id, 'document']))
        ->assertOk()
        ->assertJson([
            'kind' => 'pages',
            'count' => 2,
            'truncated' => false,
            'pages' => [
                ['url' => 'http://127.0.0.1/', 'title' => 'Home', 'text' => "Hand-stitched trekking bags from Kathmandu.\nOrder now on WhatsApp."],
                // The line already on the home page is left out, as in the kept document.
                ['url' => 'http://169.254.169.254/latest/meta-data', 'title' => 'Products', 'text' => 'The Trailblazer 40 is a waterproof bag that costs Rs 7,500.'],
            ],
        ]);

    // The pages' addresses are private ones: previewing never reached them.
    Http::assertNothingSent();

    // Only the document suggestion has a preview.
    $this->getJson(route('socialhq.brand.website.preview', [$crawl->id, 'entry-1']))->assertNotFound();
    $this->getJson(route('socialhq.brand.website.preview', [$crawl->id, 'nothing-here']))->assertNotFound();
});

test('a long website text is cut to 100 KB', function () {
    $crawl = previewCrawl($this->workspace, $this->owner);
    CrawledPage::query()->where('title', 'Home')->update(['text' => "Hand-stitched trekking bags.\n".str_repeat('Kathmandu ', 15000)]);

    $preview = $this->actingAs($this->owner)->getJson(route('socialhq.brand.website.preview', [$crawl->id, 'document']))->assertOk()->json();

    expect($preview['truncated'])->toBeTrue()
        ->and($preview['pages'])->toHaveCount(1)
        ->and(strlen($preview['pages'][0]['text']))->toBeLessThanOrEqual(100 * 1024);
});

test('only workspace admins preview a website suggestion, of their own brand\'s finished read', function () {
    $crawl = previewCrawl($this->workspace, $this->owner);

    $this->actingAs(workspaceMember($this->workspace))->getJson(route('socialhq.brand.website.preview', [$crawl->id, 'document']))->assertForbidden();

    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $otherWorkspace->members()->attach($other->id, membershipPivot('admin'));
    $other->update(['current_workspace_id' => $otherWorkspace->id]);
    $this->actingAs($other->fresh())->getJson(route('socialhq.brand.website.preview', [$crawl->id, 'document']))->assertNotFound();

    // A read still running has no suggestions to preview yet.
    $crawl->update(['status' => CrawlStatus::Reading]);
    $this->actingAs($this->owner)->getJson(route('socialhq.brand.website.preview', [$crawl->id, 'document']))->assertNotFound();
});
