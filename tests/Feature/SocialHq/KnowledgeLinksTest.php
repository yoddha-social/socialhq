<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use App\SocialHq\Knowledge\Documents\DocumentFailure;
use App\SocialHq\Knowledge\Documents\DocumentSource;
use App\SocialHq\Knowledge\Documents\DocumentStatus;
use App\SocialHq\Knowledge\Documents\DocumentStorage;
use App\SocialHq\Knowledge\Documents\KnowledgeChunk;
use App\SocialHq\Knowledge\Documents\KnowledgeDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

const GOOGLE_FILE_ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz012345';

beforeEach(function () {
    Storage::fake(DocumentStorage::DISK);
    fakePublicDns();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();
});

function addGoogleLink(User $user, string $url, array $data = []): TestResponse
{
    return test()->actingAs($user)->post(route('socialhq.brand.links.store'), ['url' => $url, ...$data]);
}

function googleTextExport(string $text, string $name = 'Price list'): array
{
    return [$text, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$name}.txt\"; filename*=UTF-8''".rawurlencode($name).'.txt']];
}

/**
 * @param  array<string, list<list<string|int>>>  $sheets
 */
function googleSheetBytes(array $sheets): string
{
    $path = tempnam(sys_get_temp_dir(), 'shq-gsheet');
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $first = true;

    foreach ($sheets as $name => $rows) {
        if (! $first) {
            $writer->addNewSheetAndMakeItCurrent();
        }

        $writer->getCurrentSheet()->setName($name);
        $first = false;

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }

    $writer->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

test('a link-shared Google Doc becomes a knowledge document named after the file', function () {
    Http::fake([
        'docs.google.com/document/d/'.GOOGLE_FILE_ID.'/export*' => Http::response(...googleTextExport("\u{FEFF}Trailblazer 40 costs Rs 7,500.\n\nFree delivery inside the Ring Road.", 'Price list')),
    ]);

    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit?usp=sharing')->assertSessionHasNoErrors();

    $document = KnowledgeDocument::query()->sole();
    expect($document->status)->toBe(DocumentStatus::Ready)
        ->and($document->source)->toBe(DocumentSource::GoogleDoc)
        ->and($document->source_url)->toBe('https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit')
        ->and($document->title)->toBe('Price list')
        ->and($document->chunks()->pluck('text')->implode("\n"))->toContain('Trailblazer 40 costs Rs 7,500.')
        ->and(Storage::disk(DocumentStorage::DISK)->exists($document->path))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/export?format=txt');
    $this->get(route('socialhq.brand.documents.show', $document->id))->assertOk();
});

test('a Google Sheet is read sheet by sheet, and Slides as plain text', function () {
    $sheetId = str_repeat('S', 30);
    $slidesId = str_repeat('P', 30);
    Http::fake([
        "docs.google.com/spreadsheets/d/{$sheetId}/export*" => Http::response(googleSheetBytes([
            'Prices' => [['Bag', 'Price'], ['Trailblazer 40', 7500], [], ['Summit 30', 6200]],
            'Shops' => [['Thamel', 'Open 10 to 8']],
        ]), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
        "docs.google.com/presentation/d/{$slidesId}/export/txt" => Http::response(...googleTextExport('Our story: hand-stitched in Kathmandu since 2009.', 'Company deck')),
    ]);

    addGoogleLink($this->owner, "https://docs.google.com/spreadsheets/d/{$sheetId}/edit#gid=0", ['title' => 'Prices'])->assertSessionHasNoErrors();
    addGoogleLink($this->owner, "https://docs.google.com/presentation/d/{$slidesId}/edit")->assertSessionHasNoErrors();

    $sheet = KnowledgeDocument::query()->where('source', DocumentSource::GoogleSheet->value)->sole();
    $text = (string) Storage::disk(DocumentStorage::DISK)->get($sheet->path);
    expect($sheet->status)->toBe(DocumentStatus::Ready)
        ->and($sheet->title)->toBe('Prices')
        ->and($text)->toContain("Sheet: Prices\nBag | Price\nTrailblazer 40 | 7500\nSummit 30 | 6200")
        ->toContain("Sheet: Shops\nThamel | Open 10 to 8");

    $slides = KnowledgeDocument::query()->where('source', DocumentSource::GoogleSlides->value)->sole();
    expect($slides->status)->toBe(DocumentStatus::Ready)
        ->and($slides->title)->toBe('Company deck');
});

test('a file that is not shared with the link fails with what to do', function (array|string $answer) {
    Http::fake(['docs.google.com/*' => is_array($answer) ? Http::response(...$answer) : Http::response('', 302, ['Location' => $answer])]);

    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit')->assertSessionHasNoErrors();

    $document = KnowledgeDocument::query()->sole();
    expect($document->status)->toBe(DocumentStatus::Failed)
        ->and($document->failure)->toBe(DocumentFailure::NotShared);

    $this->get(route('socialhq.brand.index'))->assertInertia(fn ($page) => $page->where('documents.items.0.failure', 'not_shared'));
})->with([
    'sign-in redirect' => ['https://accounts.google.com/ServiceLogin?continue=https://docs.google.com/document/d/x'],
    'forbidden' => [['', 403]],
    'sign-in page' => [['<html><body>Sign in</body></html>', 200, ['Content-Type' => 'text/html']]],
]);

test('only Google Docs, Sheets and Slides links are taken, and nothing is fetched for others', function (string $url) {
    Http::fake();

    addGoogleLink($this->owner, $url)->assertSessionHasErrors('url');

    expect(KnowledgeDocument::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'another host' => ['https://evil.example/document/d/'.GOOGLE_FILE_ID.'/edit'],
    'a look-alike host' => ['https://docs.google.com.evil.example/document/d/'.GOOGLE_FILE_ID.'/edit'],
    'plain http' => ['http://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit'],
    'with a user' => ['https://me@docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit'],
    'with a port' => ['https://docs.google.com:8443/document/d/'.GOOGLE_FILE_ID.'/edit'],
    'a short id' => ['https://docs.google.com/document/d/abc/edit'],
    'a Drive folder' => ['https://drive.google.com/drive/folders/'.GOOGLE_FILE_ID],
]);

test('a redirect away from Google is not followed', function () {
    Http::fake([
        'docs.google.com/*' => Http::response('', 302, ['Location' => 'https://evil.example/steal']),
        'evil.example/*' => Http::response('secret', 200),
    ]);

    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit');

    expect(KnowledgeDocument::query()->sole()->status)->toBe(DocumentStatus::Failed);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example'));
});

test('a redirect to a private address is refused', function () {
    $this->mock(HostResolver::class)->shouldReceive('addresses')->andReturnUsing(
        fn (string $host): array => str_ends_with($host, 'googleusercontent.com') ? ['10.0.0.5'] : ['142.250.1.1'],
    );
    Http::fake([
        'docs.google.com/*' => Http::response('', 307, ['Location' => 'https://doc-0s-docs.googleusercontent.com/export/x']),
        '*' => Http::response('internal', 200, ['Content-Type' => 'text/plain']),
    ]);

    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit');

    expect(KnowledgeDocument::query()->sole()->failure)->toBe(DocumentFailure::LinkUnreachable);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleusercontent.com'));
});

test('Read again keeps the excerpts of an unchanged file and reads a changed one, keeping a typed title', function () {
    $answers = [
        googleTextExport('Trailblazer 40 costs Rs 7,500.', 'Price list'),
        googleTextExport('Trailblazer 40 costs Rs 7,500.', 'Price list'),
        googleTextExport('Trailblazer 40 now costs Rs 6,900.', 'Prices 2083'),
        googleTextExport('Trailblazer 40 now costs Rs 6,500.', 'Prices 2084'),
    ];
    Http::fake(['docs.google.com/*' => function () use (&$answers) {
        return Http::response(...array_shift($answers));
    }]);

    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit');
    $document = KnowledgeDocument::query()->sole();
    $chunkIds = KnowledgeChunk::query()->pluck('id')->all();

    $this->post(route('socialhq.brand.documents.refresh', $document->id))->assertRedirect();
    expect(KnowledgeChunk::query()->pluck('id')->all())->toBe($chunkIds)
        ->and($document->fresh()->status)->toBe(DocumentStatus::Ready);

    $this->post(route('socialhq.brand.documents.refresh', $document->id));
    $document->refresh();
    expect($document->title)->toBe('Prices 2083')
        ->and($document->chunks()->pluck('text')->implode(' '))->toContain('Rs 6,900')->not->toContain('Rs 7,500')
        ->and(Storage::disk(DocumentStorage::DISK)->allFiles(DocumentStorage::directory((string) $this->workspace->id)))->toHaveCount(1);

    $this->put(route('socialhq.brand.documents.update', $document->id), ['title' => 'Our prices', 'use_in_ai' => true]);
    $this->post(route('socialhq.brand.documents.refresh', $document->id));
    expect($document->fresh()->title)->toBe('Our prices');
});

test('links count toward the documents limit, and the same file is added once', function () {
    config(['socialhq.knowledge.documents.max_documents' => 1]);
    Http::fake(['docs.google.com/*' => Http::response(...googleTextExport('Open daily from ten in the morning.'))]);

    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit')->assertSessionHasNoErrors();
    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/view')->assertSessionHasErrors('url');

    config(['socialhq.knowledge.documents.max_documents' => 5]);
    addGoogleLink($this->owner, 'https://docs.google.com/document/u/0/d/'.GOOGLE_FILE_ID.'/edit')->assertSessionHasErrors('url');
    expect(KnowledgeDocument::query()->count())->toBe(1);
});

test('members can\'t add or read links again, and another workspace\'s document is not found', function () {
    Http::fake(['docs.google.com/*' => Http::response(...googleTextExport('Open daily from ten in the morning.'))]);
    addGoogleLink($this->owner, 'https://docs.google.com/document/d/'.GOOGLE_FILE_ID.'/edit');
    $document = KnowledgeDocument::query()->sole();

    $member = workspaceMember($this->workspace);
    addGoogleLink($member, 'https://docs.google.com/document/d/'.str_repeat('Z', 30).'/edit')->assertForbidden();
    $this->actingAs($member)->post(route('socialhq.brand.documents.refresh', $document->id))->assertForbidden();

    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $otherWorkspace->members()->attach($other->id, membershipPivot('admin'));
    $other->update(['current_workspace_id' => $otherWorkspace->id]);

    $this->actingAs($other->fresh())->post(route('socialhq.brand.documents.refresh', $document->id))->assertNotFound();
});
