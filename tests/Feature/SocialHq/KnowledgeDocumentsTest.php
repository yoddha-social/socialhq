<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\BrandProfile;
use App\SocialHq\Knowledge\Documents\Chunker;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\KnowledgeChunk;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use App\SocialHq\Knowledge\KnowledgeEntry;
use App\SocialHq\Knowledge\Search\KnowledgeMatch;
use App\SocialHq\Knowledge\Search\KnowledgeSearch;
use App\SocialHq\Knowledge\Search\LexicalKnowledgeSearch;
use App\SocialHq\Plan\Ai\CopyWriter;
use App\SocialHq\Plan\Ai\PlannerContext;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Ai\BriefWriter;
use App\SocialHq\Production\Brief;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
});

/**
 * An Office file built in the test: a zip of the given parts.
 *
 * @param  array<string, string>  $parts
 */
function officeZip(array $parts): string
{
    $path = tempnam(sys_get_temp_dir(), 'shq-office');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');

    foreach ($parts as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

function docxBytes(string $body): string
{
    return officeZip(['word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>']);
}

/**
 * @param  array<int, string>  $slides  Text of slideN.xml, by N
 * @param  list<int>  $order  The deck's order, by slide number
 */
function pptxBytes(array $slides, array $order): string
{
    $parts = [];
    $relationships = '';
    $ids = '';

    foreach ($slides as $number => $text) {
        $parts["ppt/slides/slide{$number}.xml"] = '<?xml version="1.0"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>'.$text.'</a:t></a:r><a:br/><a:r><a:t>Slide '.$number.' footnote</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        $relationships .= "<Relationship Id=\"rId{$number}\" Type=\"slide\" Target=\"slides/slide{$number}.xml\"/>";
    }

    foreach ($order as $index => $number) {
        $ids .= '<p:sldId id="'.(256 + $index)."\" r:id=\"rId{$number}\"/>";
    }

    $parts['ppt/_rels/presentation.xml.rels'] = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>';
    $parts['ppt/presentation.xml'] = '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst>'.$ids.'</p:sldIdLst></p:presentation>';

    return officeZip($parts);
}

function uploadDocument(User $user, UploadedFile $file, array $data = []): TestResponse
{
    return test()->actingAs($user)->post(route('socialhq.brand.documents.store'), ['file' => $file, ...$data]);
}

function fixtureFile(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path("tests/fixtures/socialhq/{$name}")));
}

function readyDocument(Workspace $workspace, string $title, array $chunks, array $attributes = []): KnowledgeDocument
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
        'chunk_count' => count($chunks),
        ...$attributes,
    ]);

    foreach (array_values($chunks) as $position => $text) {
        KnowledgeChunk::query()->create(['document_id' => $document->id, 'workspace_id' => $workspace->id, 'position' => $position, 'text' => $text]);
    }

    return $document;
}

function docEntry(Workspace $workspace, array $attributes = []): KnowledgeEntry
{
    return KnowledgeEntry::query()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'product',
        'title' => 'Momo Express',
        'body' => 'Delivery in 30 minutes inside the Ring Road.',
        'use_in_ai' => true,
        ...$attributes,
    ]);
}

test('an admin uploads a PDF brochure and its text becomes searchable excerpts', function () {
    uploadDocument($this->owner, fixtureFile('brochure.pdf'))->assertRedirect()->assertSessionHasNoErrors();

    $document = KnowledgeDocument::query()->sole();

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->title)->toBe('brochure')
        ->and($document->pages)->toBe(2)
        ->and($document->mime)->toBe('application/pdf')
        ->and($document->path)->toStartWith("socialhq/knowledge/{$this->workspace->id}/")
        ->and($document->path)->toEndWith('.pdf')
        ->and($document->chunk_count)->toBeGreaterThan(0)
        ->and($document->characters)->toBeGreaterThan(50);

    Storage::disk(DocumentStorage::DISK)->assertExists($document->path);

    $text = $document->chunks()->orderBy('position')->pluck('text')->implode("\n");
    expect($text)->toContain('Trailblazer 40 trekking bag')
        ->toContain('Rs 7,500')
        ->toContain('Summit Lite');

    $this->actingAs($this->owner)
        ->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page
            ->where('documents.items.0.id', $document->id)
            ->where('documents.items.0.status', 'ready')
            ->where('documents.items.0.type', 'pdf')
            ->where('documents.items.0.pages', 2)
            ->where('documents.limit', 30));
});

test('a Word file keeps its paragraphs, line breaks and table rows', function () {
    $body = '<w:p><w:r><w:t>Price list 2026</w:t></w:r></w:p>'
        .'<w:p><w:r><w:t xml:space="preserve">Delivery </w:t></w:r><w:r><w:t>inside the Ring Road</w:t></w:r><w:r><w:br/><w:t>Free above Rs 1,000</w:t></w:r></w:p>'
        .'<w:p><w:del><w:r><w:delText>Old price</w:delText></w:r></w:del></w:p>'
        .'<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Trailblazer 40</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Rs 7,500</w:t></w:r></w:p></w:tc></w:tr>'
        .'<w:tr><w:tc><w:p><w:r><w:t>Summit Lite</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Rs 3,200</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';

    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('Price list.docx', docxBytes($body)), ['title' => 'Prices'])
        ->assertSessionHasNoErrors();

    $document = KnowledgeDocument::query()->sole();
    $text = $document->chunks()->orderBy('position')->pluck('text')->implode("\n");

    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->title)->toBe('Prices')
        ->and($document->file_name)->toBe('Price list.docx')
        ->and($text)->toContain("Price list 2026\n\nDelivery inside the Ring Road\nFree above Rs 1,000")
        ->and($text)->toContain('Trailblazer 40 | Rs 7,500')
        ->and($text)->toContain('Summit Lite | Rs 3,200')
        ->and($text)->not->toContain('Old price');
});

test('a PowerPoint deck is read slide by slide in the deck\'s order', function () {
    $bytes = pptxBytes([1 => 'Our story since 2010', 2 => 'Dashain offer: 20 percent off trekking bags'], order: [2, 1]);

    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('Deck.pptx', $bytes))->assertSessionHasNoErrors();

    $document = KnowledgeDocument::query()->sole();
    $text = $document->chunks()->orderBy('position')->pluck('text')->implode("\n");

    expect($document->pages)->toBe(2)
        ->and($text)->toContain("Dashain offer: 20 percent off trekking bags\nSlide 2 footnote")
        ->and(strpos($text, 'Dashain offer'))->toBeLessThan(strpos($text, 'Our story'));
});

test('text and Markdown files are read as UTF-8, without control characters', function () {
    $latin = mb_convert_encoding("Caf\u{00E9} menu: momo, chowmein and thukpa for everyone.\x07\n\n\n\nOpen   daily.", 'Windows-1252', 'UTF-8');

    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('menu.txt', $latin))->assertSessionHasNoErrors();
    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('faq.md', "# दशैं अफर\n\nसबै झोलामा २० प्रतिशत छुट। Valid all week."))->assertSessionHasNoErrors();

    $menu = KnowledgeDocument::query()->where('file_name', 'menu.txt')->sole();
    $faq = KnowledgeDocument::query()->where('file_name', 'faq.md')->sole();

    expect($menu->chunks()->value('text'))->toBe("Café menu: momo, chowmein and thukpa for everyone.\n\nOpen daily.")
        ->and($menu->type()?->value)->toBe('txt')
        ->and($faq->type()?->value)->toBe('md')
        ->and($faq->chunks()->value('text'))->toContain('सबै झोलामा २० प्रतिशत छुट।');
});

test('a scanned PDF with no text fails with a reason the page shows', function () {
    uploadDocument($this->owner, fixtureFile('scanned.pdf'))->assertSessionHasNoErrors();

    $document = KnowledgeDocument::query()->sole();

    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure?->value)->toBe('no_text')
        ->and($document->chunks()->count())->toBe(0)
        ->and(__('socialhq_knowledge.documents.failures.no_text'))->toBe('We couldn\'t find any text in this file. If it\'s a scan, paste the text as a knowledge entry instead.');

    $this->actingAs($this->owner)->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page->where('documents.items.0.status', 'failed')->where('documents.items.0.failure', 'no_text'));
});

test('a file is refused when its content is not one of the supported types', function () {
    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('brochure.pdf', (string) file_get_contents(base_path('tests/fixtures/1x1.png'))))
        ->assertSessionHasErrors(['file' => __('socialhq_knowledge.documents.errors.type')]);
    // A zip that is no Office file, and a PDF named as Word.
    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('notes.docx', officeZip(['notes.xml' => '<a/>'])))->assertSessionHasErrors('file');
    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('brochure.docx', (string) file_get_contents(base_path('tests/fixtures/socialhq/brochure.pdf'))))->assertSessionHasErrors('file');

    expect(KnowledgeDocument::query()->count())->toBe(0)
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles())->toBe([]);
});

test('uploads stop at the size and document limits', function () {
    config(['socialhq.knowledge.documents.max_kilobytes' => 1024]);

    uploadDocument($this->owner, UploadedFile::fake()->create('huge.pdf', 1025, 'application/pdf'))
        ->assertSessionHasErrors(['file' => __('socialhq_knowledge.documents.errors.too_large', ['size' => 1])]);

    config(['socialhq.knowledge.documents.max_documents' => 1]);
    readyDocument($this->workspace, 'Brochure', ['Bags.']);

    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('menu.txt', str_repeat('Momo and thukpa. ', 5)))
        ->assertSessionHasErrors(['file' => __('socialhq_knowledge.documents.errors.too_many', ['count' => 1])]);

    expect(KnowledgeDocument::query()->count())->toBe(1);
});

test('a zip bomb is refused before it is read', function () {
    config(['socialhq.knowledge.documents.max_expanded_bytes' => 1024 * 1024]);
    $bomb = docxBytes('<w:p><w:r><w:t>'.str_repeat(' ', 2 * 1024 * 1024).'Hello</w:t></w:r></w:p>');

    expect(strlen($bomb))->toBeLessThan(100 * 1024);

    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('bomb.docx', $bomb))->assertSessionHasNoErrors();

    expect(KnowledgeDocument::query()->sole())
        ->status->toBe(DocumentStatus::Failed)
        ->failure->value->toBe('too_big');
});

test('long text is cut at the limit, and noted', function () {
    config(['socialhq.knowledge.documents.max_characters' => 2000]);

    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('long.txt', str_repeat('Trekking bags for every trail. ', 200)))->assertSessionHasNoErrors();

    $document = KnowledgeDocument::query()->sole();

    expect($document->truncated)->toBeTrue()
        ->and($document->characters)->toBeLessThanOrEqual(2000)
        ->and($document->chunk_count)->toBeGreaterThan(1);
});

test('excerpts follow paragraphs and sentences and stay near the target size', function () {
    $text = implode("\n\n", [str_repeat('Short paragraph. ', 10), str_repeat('A much longer sentence about bags and trails. ', 40), 'नमस्ते। '.str_repeat('दशैं अफर। ', 30)]);

    $chunks = Chunker::split($text, size: 300, overlap: 60, max: 100);

    expect($chunks)->not->toBeEmpty()
        ->and(collect($chunks)->every(fn (string $chunk): bool => mb_strlen($chunk) <= 360))->toBeTrue()
        ->and($chunks[0])->toStartWith('Short paragraph.')
        ->and(collect($chunks)->contains(fn (string $chunk): bool => str_contains($chunk, 'दशैं अफर।')))->toBeTrue()
        ->and(Chunker::split($text, size: 300, overlap: 60, max: 2))->toHaveCount(2);
});

test('members read and download documents but only admins change them', function () {
    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('menu.txt', str_repeat('Momo and thukpa. ', 5)));
    $document = KnowledgeDocument::query()->sole();
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page->where('canEdit', false)->has('documents.items', 1));

    $this->actingAs($member)->get(route('socialhq.brand.documents.show', $document->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertDownload('menu.txt');

    uploadDocument($member, UploadedFile::fake()->createWithContent('other.txt', 'Anything at all, long enough to read.'))->assertForbidden();
    $this->actingAs($member)->put(route('socialhq.brand.documents.update', $document->id), ['title' => 'Changed'])->assertForbidden();
    $this->actingAs($member)->delete(route('socialhq.brand.documents.destroy', $document->id))->assertForbidden();

    $this->actingAs($this->owner)->put(route('socialhq.brand.documents.update', $document->id), ['title' => 'Menu', 'use_in_ai' => false])->assertRedirect();

    expect($document->fresh())->title->toBe('Menu')->use_in_ai->toBeFalse()
        ->and(KnowledgeDocument::query()->count())->toBe(1);
});

test('another workspace\'s documents are not found', function () {
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $document = readyDocument($other, 'Secret', ['Their prices.']);

    $this->actingAs($this->owner)->get(route('socialhq.brand.documents.show', $document->id))->assertNotFound();
    $this->actingAs($this->owner)->put(route('socialhq.brand.documents.update', $document->id), ['title' => 'Hijack'])->assertNotFound();
    $this->actingAs($this->owner)->delete(route('socialhq.brand.documents.destroy', $document->id))->assertNotFound();

    expect($document->fresh()->title)->toBe('Secret');
});

test('deleting a document removes its file and excerpts, and deleting the workspace removes its files', function () {
    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('menu.txt', str_repeat('Momo and thukpa. ', 5)));
    uploadDocument($this->owner, UploadedFile::fake()->createWithContent('faq.txt', str_repeat('Open daily from ten. ', 5)));
    [$menu, $faq] = KnowledgeDocument::query()->orderBy('id')->get()->all();
    $disk = Storage::disk(DocumentStorage::DISK);

    $this->actingAs($this->owner)->delete(route('socialhq.brand.documents.destroy', $menu->id))->assertRedirect();

    $disk->assertMissing($menu->path);
    $disk->assertExists($faq->path);
    expect(KnowledgeChunk::query()->where('document_id', $menu->id)->count())->toBe(0);

    $this->workspace->delete();

    $disk->assertMissing($faq->path);
    expect(KnowledgeDocument::query()->count())->toBe(0)
        ->and(KnowledgeChunk::query()->count())->toBe(0);
});

test('search ranks the matching entry and excerpt first, in English and Nepali', function () {
    $this->travel(-1)->days();
    $bags = docEntry($this->workspace, ['title' => 'Trekking bags', 'body' => 'Waterproof bags for the Annapurna trails.']);
    $this->travelBack();
    docEntry($this->workspace, ['title' => 'Momo Express', 'body' => 'Delivery in 30 minutes.']);
    docEntry($this->workspace, ['title' => 'Hidden prices', 'body' => 'Bag prices.', 'use_in_ai' => false]);
    $festival = docEntry($this->workspace, ['title' => 'दशैं अफर', 'body' => 'सबै झोलामा २० प्रतिशत छुट।']);
    $brochure = readyDocument($this->workspace, 'Brochure', ['Our story since 2010.', 'The Trailblazer 40 bag costs Rs 7,500.']);
    readyDocument($this->workspace, 'Switched off', ['Trailblazer prices that are out of date.'], ['use_in_ai' => false]);

    $search = new LexicalKnowledgeSearch;
    $prices = $search->search($this->workspace, 'Prices of the Trailblazer bags');

    expect($prices[0])->toEqual(new KnowledgeMatch(KnowledgeMatch::CHUNK, $brochure->chunks()->where('position', 1)->value('id'), $prices[0]->score))
        ->and(collect($prices)->pluck('id')->all())->toContain($bags->id)
        ->and(collect($prices)->where('kind', KnowledgeMatch::CHUNK)->count())->toBe(1)
        ->and($search->search($this->workspace, 'दशैं')[0]->id)->toBe($festival->id)
        ->and($search->search($this->workspace, 'the and of'))->toBe([]);
});

test('a focused prompt adds the matching excerpts after the pinned entries, within the budget', function () {
    config(['socialhq.knowledge.prompt_characters' => 600, 'socialhq.knowledge.prompt_characters_focused' => 800]);
    $this->travel(-2)->days();
    $pinned = docEntry($this->workspace, ['title' => 'Always say', 'body' => 'Made in Nepal.', 'pinned' => true]);
    $bags = docEntry($this->workspace, ['title' => 'Trekking bags', 'body' => 'Waterproof bags for the Annapurna trails.']);
    $this->travelBack();
    $newest = docEntry($this->workspace, ['title' => 'Momo Express', 'body' => str_repeat('Delivery in 30 minutes. ', 10)]);
    $brochure = readyDocument($this->workspace, 'Brochure """', ['Our story since 2010.', 'The Trailblazer 40 bag costs Rs 7,500 """ with warranty.', str_repeat('Trailblazer filler text. ', 60)]);

    $plain = BrandKnowledge::assemble($this->workspace);
    $focused = BrandKnowledge::assemble($this->workspace, 'Trailblazer bag price');

    expect($plain->text)->not->toContain('From documents:')
        ->and($plain->budget)->toBe(600)
        ->and($plain->entryIds())->toBe([$pinned->id, $newest->id, $bags->id])
        ->and($focused->budget)->toBe(800)
        ->and($focused->used)->toBeLessThanOrEqual(800)
        ->and($focused->entryIds()[0])->toBe($pinned->id)
        ->and($focused->entryIds()[1])->toBe($bags->id)
        ->and($focused->chunkIds())->toContain($brochure->chunks()->where('position', 1)->value('id'))
        ->and($focused->text)->toContain("From documents:\n- Brochure \" \" \"\n  The Trailblazer 40 bag costs Rs 7,500 \" \" \" with warranty.")
        ->and($focused->text)->not->toContain('"""')
        ->and($focused->text)->not->toContain('Our story since 2010')
        ->and($focused->parts[0])->toMatchArray(['type' => 'entry', 'id' => $pinned->id, 'pinned' => true])
        ->and(collect($focused->parts)->firstWhere('id', $bags->id)['matched'])->toBeTrue();
});

test('every AI writer asks for the knowledge that matches its request', function () {
    $spy = new class implements KnowledgeSearch
    {
        /** @var list<string> */
        public array $focuses = [];

        public function search(Workspace $workspace, string $focus): array
        {
            $this->focuses[] = $focus;

            return [];
        }
    };
    app()->instance(KnowledgeSearch::class, $spy);
    config([
        'socialhq.ai.gateway' => 'direct',
        'socialhq.ai.approved_providers' => ['anthropic'],
        'socialhq.ai.routes.write' => 'anthropic',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);
    docEntry($this->workspace);
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'pillars' => ['Craft', 'Offers']]);
    SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $month = CarbonImmutable::now('UTC')->addMonthNoOverflow()->startOfMonth();
    CalendarEvent::factory()->verified()->create(['account_id' => $this->workspace->account_id, 'name' => 'Tihar', 'starts_on' => $month->addDays(10), 'ends_on' => $month->addDays(11)]);
    CalendarEvent::factory()->create(['account_id' => $this->workspace->account_id, 'name' => 'Unchecked day', 'starts_on' => $month->addDays(12), 'ends_on' => $month->addDays(12)]);
    $plan = ContentPlan::factory()->create(['workspace_id' => $this->workspace->id, 'month' => $month->toDateString(), 'goals' => 'Sell more trekking bags']);
    $item = PlanItem::factory()->create(['plan_id' => $plan->id, 'title' => 'Trailblazer launch', 'angle' => 'Built for monsoon trails']);

    PlannerContext::build($plan->fresh(), CarbonImmutable::now('UTC')->startOfDay());
    expect($spy->focuses[0])->toContain('Sell more trekking bags')->toContain('Craft, Offers')->toContain('Tihar')->toContain('Instagram')
        ->not->toContain('Unchecked day');

    CopyWriter::fake([['caption' => 'New bag.', 'hashtags' => '', 'script' => '', 'notes' => '']]);
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.copy.ai', $item->id))->assertCreated();
    expect($spy->focuses[1])->toContain('Trailblazer launch')->toContain('Built for monsoon trails');

    BriefWriter::fake([['on_screen_text' => 'New', 'direction' => 'Rain', 'references' => '']]);
    Brief::factory()->for($item, 'item')->create();
    ItemVersion::factory()->for($item, 'item')->create(['caption' => 'Rain-proof from the first step']);
    $this->actingAs($this->owner)->postJson(route('socialhq.plan.items.brief.ai', $item->id))->assertOk();
    expect($spy->focuses[2])->toContain('Trailblazer launch')->toContain('Rain-proof from the first step');

    $this->actingAs($this->owner);
    (new IdeaGenerator(Locale::DEFAULT, 'A trekking gear shop', 'Hikers', 'Monsoon season'))->instructions();
    expect($spy->focuses[3])->toContain('A trekking gear shop')->toContain('Monsoon season');

    (new PostWritingAssistant(PostAssistantMode::Shorten, '<p>Our <strong>Trailblazer</strong> bag</p>', Locale::DEFAULT))->instructions();
    expect($spy->focuses[4])->toBe('Our Trailblazer bag');
});

test('check what the AI reads shows what a topic gets, in order', function () {
    BrandProfile::query()->create(['workspace_id' => $this->workspace->id, 'about' => 'A trekking gear shop.']);
    $entry = docEntry($this->workspace, ['title' => 'Trekking bags', 'body' => 'Waterproof bags.']);
    $brochure = readyDocument($this->workspace, 'Brochure', ['The Trailblazer 40 bag costs Rs 7,500.']);
    $member = workspaceMember($this->workspace);

    $this->actingAs($member)->postJson(route('socialhq.brand.search'), ['focus' => 'Trailblazer bag price'])
        ->assertOk()
        ->assertJsonPath('budget', 8000)
        ->assertJsonPath('profile.used', BrandKnowledge::size("What the brand is:\nA trekking gear shop."))
        ->assertJsonPath('parts.0.type', 'excerpt')
        ->assertJsonPath('parts.0.title', 'Brochure')
        ->assertJsonPath('parts.0.document_id', $brochure->id)
        ->assertJsonPath('parts.1.id', $entry->id)
        ->assertJsonPath('parts.1.matched', true);

    $this->actingAs($member)->postJson(route('socialhq.brand.search'), ['focus' => ''])->assertUnprocessable();
    $this->actingAs(workspaceOutsider($this->workspace))->postJson(route('socialhq.brand.search'), ['focus' => 'bags'])->assertForbidden();
});
