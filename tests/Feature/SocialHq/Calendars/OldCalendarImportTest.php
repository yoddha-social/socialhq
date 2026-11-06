<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\Calendars\CalendarImport;
use App\SocialHq\Knowledge\Calendars\CalendarImportStatus;
use App\SocialHq\Knowledge\Calendars\CalendarStorage;
use App\SocialHq\Knowledge\Calendars\PastPost;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->owner = $this->owner->fresh();

    // A day in the middle of last year's calendar: always in the past, never ambiguous between 1 and 12.
    $this->lastYear = CarbonImmutable::now('UTC')->subYear()->startOfYear()->addMonths(9)->addDays(2);
});

/**
 * A CSV upload written with OpenSpout.
 *
 * @param  list<list<string|int|float|null>>  $rows
 */
function oldCalendarCsv(array $rows, string $name = 'calendar.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'shq-cal');
    $writer = new CsvWriter;
    $writer->openToFile($path);

    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }

    $writer->close();

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

/**
 * An XLSX upload: sheet name => rows of values or cells.
 *
 * @param  array<string, list<list<mixed>>>  $sheets
 */
function oldCalendarXlsx(array $sheets, string $name = 'calendar.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'shq-cal');
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $first = true;

    foreach ($sheets as $sheetName => $rows) {
        if (! $first) {
            $writer->addNewSheetAndMakeItCurrent();
        }

        $writer->getCurrentSheet()->setName($sheetName);
        $first = false;

        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (mixed $value): Cell => $value instanceof Cell ? $value : Cell::fromValue($value), $row)));
        }
    }

    $writer->close();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/**
 * Upload a file, then import it with the guessed mapping (or the one given).
 *
 * @param  array<string, mixed>  $overrides
 */
function importOldCalendar(object $test, UploadedFile $file, array $overrides = []): CalendarImport
{
    $upload = $test->actingAs($test->owner)
        ->post(route('socialhq.brand.calendars.store'), ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json();

    $sheet = $upload['sheets'][0];

    $test->actingAs($test->owner)
        ->putJson(route('socialhq.brand.calendars.update', $upload['id']), [
            'sheet' => $sheet['index'],
            'mapping' => $sheet['mapping'],
            'date_order' => $sheet['date_order'],
            ...$overrides,
        ])
        ->assertOk();

    return CalendarImport::query()->findOrFail($upload['id']);
}

test('an upload is kept on the private disk and returns the header, samples and a guessed mapping', function () {
    $date = $this->lastYear->format('Y-m-d');
    $file = oldCalendarCsv([
        ['Miti', 'Platform', 'Post type', 'Topic', 'Caption copy', 'Content pillar', 'Objective', 'Likes', 'Reach', 'Impressions', 'Post link'],
        [$date, 'IG', 'Reel', 'Dashain menu', 'Our Dashain momo platter is here.', 'Menu', 'Drive orders', '1,204', '1.2k', '3400', 'https://instagram.com/p/abc'],
        [$date, 'FB', 'Photo', 'Team lunch', '', 'Team', '', '88', '', '', ''],
    ]);

    $response = $this->actingAs($this->owner)
        ->post(route('socialhq.brand.calendars.store'), ['file' => $file], ['Accept' => 'application/json'])
        ->assertCreated();

    $import = CalendarImport::query()->findOrFail($response->json('id'));

    expect($import->status)->toBe(CalendarImportStatus::Pending)
        ->and($import->file_name)->toBe('calendar.csv')
        ->and($import->path)->toStartWith(CalendarStorage::directory($this->workspace).'/');
    Storage::disk('local')->assertExists($import->path);

    expect($response->json('sheets'))->toHaveCount(1)
        ->and($response->json('sheets.0.header_row'))->toBe(1)
        ->and($response->json('sheets.0.rows'))->toBe(2)
        ->and($response->json('sheets.0.columns.0'))->toBe(['index' => 0, 'label' => 'Miti', 'letter' => 'A'])
        ->and($response->json('sheets.0.samples.0.3'))->toBe('Dashain menu')
        ->and($response->json('sheets.0.mapping'))->toBe([
            'date' => 0, 'channel' => 1, 'format' => 2, 'title' => 3, 'caption' => 4, 'pillar' => 5,
            'notes' => 6, 'engagement' => 7, 'reach' => 8, 'views' => 9, 'link' => 10,
        ]);
});

test('importing stores the rows as past posts, recognises channels, formats and counts, and deletes the file', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::InstagramFacebook]);
    $date = $this->lastYear->format('Y-m-d');

    $import = importOldCalendar($this, oldCalendarCsv([
        ['Date', 'Channel', 'Format', 'Title', 'Caption', 'Likes', 'Reach', 'Views', 'Link'],
        [$date, 'Insta', 'Reel', 'Dashain menu', 'Our Dashain platter.', '1,204', '1.2k', '3M', 'https://instagram.com/p/abc'],
        [$date, 'FB + IG', 'Carousel video', 'Both channels', '', '', '', '', 'javascript:alert(1)'],
    ]));

    $path = CalendarImport::query()->whereKey($import->id)->value('path');
    $rows = PastPost::query()->where('calendar_import_id', $import->id)->orderBy('row_number')->get();

    expect($import->status)->toBe(CalendarImportStatus::Imported)
        ->and($path)->toBeNull()
        ->and($import->rows_imported)->toBe(2)
        ->and($import->first_date->toDateString())->toBe($date)
        ->and($rows[0])->toMatchArray([
            'workspace_id' => $this->workspace->id,
            'row_number' => 2,
            'channel_label' => 'Insta',
            'format' => 'Reel',
            'title' => 'Dashain menu',
            'engagement' => 1204,
            'reach' => 1200,
            'views' => 3_000_000,
            'link' => 'https://instagram.com/p/abc',
        ])
        ->and($rows[0]->posted_on->toDateString())->toBe($date)
        ->and($rows[0]->platform)->toBe(Platform::InstagramFacebook)
        ->and($rows[0]->content_type)->toBe(ContentType::InstagramReel)
        ->and($rows[1]->platform)->toBeNull()
        ->and($rows[1]->content_type)->toBeNull()
        ->and($rows[1]->link)->toBeNull();
    expect(Storage::disk('local')->allFiles(CalendarStorage::directory($this->workspace)))->toBe([]);
});

test('an xlsx with its header on row 3, Excel date cells and two sheets imports the picked sheet', function () {
    $dateStyle = (new Style)->withFormat('yyyy-mm-dd');
    $day = $this->lastYear;

    $upload = $this->actingAs($this->owner)->post(route('socialhq.brand.calendars.store'), ['file' => oldCalendarXlsx([
        'October' => [
            ['Content calendar'],
            [],
            ['Date', 'Platform', 'Format', 'Topic'],
            [Cell::fromValue($day, $dateStyle), 'LinkedIn', 'Text post', 'Hiring post'],
            // A date cell without a date format: Excel's day number.
            [(int) $day->diffInDays(CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC'), true) + 1, 'YouTube', 'Video', 'Kitchen tour'],
        ],
        'Archive' => [
            ['Date', 'Topic'],
            [Cell::fromValue($day->subYear(), $dateStyle), 'Older post'],
        ],
        'Empty' => [],
    ])], ['Accept' => 'application/json'])->assertCreated()->json();

    expect($upload['sheets'])->toHaveCount(2)
        ->and(array_column($upload['sheets'], 'name'))->toBe(['October', 'Archive'])
        ->and($upload['sheets'][0]['header_row'])->toBe(3)
        ->and($upload['sheets'][0]['samples'][0][0])->toBe($day->toDateString())
        ->and($upload['sheets'][0]['mapping']['date'])->toBe(0)
        ->and($upload['sheets'][0]['mapping']['title'])->toBe(3);

    $this->actingAs($this->owner)->putJson(route('socialhq.brand.calendars.update', $upload['id']), [
        'sheet' => $upload['sheets'][0]['index'],
        'mapping' => $upload['sheets'][0]['mapping'],
        'date_order' => 'day_first',
    ])->assertOk()->assertJsonPath('calendar.rows_imported', 2);

    $rows = PastPost::query()->where('calendar_import_id', $upload['id'])->orderBy('row_number')->get();

    expect($rows->pluck('row_number')->all())->toBe([4, 5])
        ->and($rows->map(fn (PastPost $row): string => $row->posted_on->toDateString())->all())->toBe([$day->toDateString(), $day->addDay()->toDateString()])
        ->and($rows[0]->platform)->toBe(Platform::LinkedIn)
        ->and($rows[0]->content_type)->toBe(ContentType::LinkedInPost)
        ->and($rows[1]->platform)->toBe(Platform::YouTube)
        ->and($rows[1]->content_type)->toBeNull();

    $this->actingAs($this->owner)->putJson(route('socialhq.brand.calendars.update', $upload['id']), [
        'sheet' => 0, 'mapping' => ['date' => 0], 'date_order' => 'day_first',
    ])->assertUnprocessable()->assertJsonValidationErrors('rows');
});

test('slashed dates follow the chosen order, and the order is guessed when a day is above 12', function () {
    $year = $this->lastYear->year;
    $ambiguous = oldCalendarCsv([['Date', 'Title'], ["03/10/{$year}", 'First'], ["04/10/{$year}", 'Second']]);

    $upload = $this->actingAs($this->owner)->post(route('socialhq.brand.calendars.store'), ['file' => $ambiguous], ['Accept' => 'application/json'])->json();

    expect($upload['sheets'][0]['date_order'])->toBe('day_first')
        ->and($upload['sheets'][0]['date_order_certain'])->toBeFalse();

    $this->actingAs($this->owner)->putJson(route('socialhq.brand.calendars.update', $upload['id']), [
        'sheet' => 0, 'mapping' => $upload['sheets'][0]['mapping'], 'date_order' => 'month_first',
    ])->assertOk();

    expect(PastPost::query()->where('calendar_import_id', $upload['id'])->orderBy('row_number')->get()->map(fn (PastPost $row): string => $row->posted_on->toDateString())->all())
        ->toBe(["{$year}-03-10", "{$year}-04-10"]);

    $dayFirst = $this->actingAs($this->owner)->post(route('socialhq.brand.calendars.store'), [
        'file' => oldCalendarCsv([['Date', 'Title'], ["03/10/{$year}", 'First'], ["25/10/{$year}", 'Second']]),
    ], ['Accept' => 'application/json'])->json('sheets.0');
    $monthFirst = $this->actingAs($this->owner)->post(route('socialhq.brand.calendars.store'), [
        'file' => oldCalendarCsv([['Date', 'Title'], ["10/25/{$year}", 'First']]),
    ], ['Accept' => 'application/json'])->json('sheets.0');

    expect($dayFirst['date_order'])->toBe('day_first')
        ->and($dayFirst['date_order_certain'])->toBeTrue()
        ->and($monthFirst['date_order'])->toBe('month_first');
});

test('rows with a Bikram Sambat year, a future date, no date or nothing besides the date are skipped with a reason', function () {
    $past = $this->lastYear;
    $future = CarbonImmutable::now('UTC')->addMonth();

    $import = importOldCalendar($this, oldCalendarCsv([
        ['Date', 'Title'],
        [$past->format('j M Y'), 'Written out'],
        [$past->format('M j, Y'), 'American style'],
        ['2082-06-32', 'Bikram Sambat'],
        ['२०८२-०६-१७', 'Bikram Sambat in Devanagari'],
        [$future->format('Y-m-d'), 'Not posted yet'],
        ['', 'No date'],
        ['Week 3', 'Not a date'],
        [$past->format('Y-m-d'), ''],
    ]));

    expect($import->rows_imported)->toBe(2)
        ->and($import->rows_skipped)->toBe(6)
        ->and($import->skipped_reasons)->toBe([
            ['row' => 4, 'reason' => 'bikram_sambat', 'value' => '2082-06-32'],
            ['row' => 5, 'reason' => 'bikram_sambat', 'value' => '२०८२-०६-१७'],
            ['row' => 6, 'reason' => 'future_date', 'value' => $future->format('Y-m-d')],
            ['row' => 7, 'reason' => 'no_date', 'value' => null],
            ['row' => 8, 'reason' => 'unreadable_date', 'value' => 'Week 3'],
            ['row' => 9, 'reason' => 'empty', 'value' => $past->format('Y-m-d')],
        ])
        ->and(PastPost::query()->where('calendar_import_id', $import->id)->pluck('posted_on')->map->toDateString()->all())
        ->toBe([$past->toDateString(), $past->toDateString()]);
    expect(__('socialhq_calendars.skipped.reasons.bikram_sambat'))->toContain('Bikram Sambat');
});

test('a file where no row can be imported stays pending and says why', function () {
    $upload = $this->actingAs($this->owner)->post(route('socialhq.brand.calendars.store'), [
        'file' => oldCalendarCsv([['Date', 'Title'], ['2081-04-10', 'One'], ['2081-04-11', 'Two']]),
    ], ['Accept' => 'application/json'])->json();

    $this->actingAs($this->owner)->putJson(route('socialhq.brand.calendars.update', $upload['id']), [
        'sheet' => 0, 'mapping' => $upload['sheets'][0]['mapping'], 'date_order' => 'day_first',
    ])->assertUnprocessable()->assertJsonValidationErrors(['rows' => 'Bikram Sambat']);

    expect(CalendarImport::query()->findOrFail($upload['id'])->status)->toBe(CalendarImportStatus::Pending)
        ->and(PastPost::query()->count())->toBe(0);
});

test('uploads are checked for size, row count, number of imports and type by content', function () {
    $upload = fn (UploadedFile $file) => $this->actingAs($this->owner)->post(route('socialhq.brand.calendars.store'), ['file' => $file], ['Accept' => 'application/json']);

    config(['socialhq.knowledge.calendars.max_file_megabytes' => 1]);
    $upload(UploadedFile::fake()->create('big.csv', 1100, 'text/csv'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'over 1 MB']);

    config(['socialhq.knowledge.calendars.max_rows' => 3]);
    $date = $this->lastYear->format('Y-m-d');
    $upload(oldCalendarCsv([['Date', 'Title'], [$date, 'A'], [$date, 'B'], [$date, 'C'], [$date, 'D']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'more than 3 rows']);

    // CSV text named .xlsx, a zip named .csv, an old .xls.
    $upload(oldCalendarCsv([['Date'], [$date]], 'calendar.xlsx'))->assertUnprocessable()->assertJsonValidationErrors(['file' => 'isn\'t a CSV']);
    $upload(oldCalendarXlsx(['Sheet1' => [['Date'], [$date]]], 'calendar.csv'))->assertUnprocessable()->assertJsonValidationErrors(['file' => 'isn\'t a CSV']);
    $upload(UploadedFile::fake()->create('calendar.xls', 10, 'application/vnd.ms-excel'))->assertUnprocessable()->assertJsonValidationErrors('file');

    config(['socialhq.knowledge.calendars.max_imports' => 1]);
    CalendarImport::factory()->create(['workspace_id' => $this->workspace->id]);
    $upload(oldCalendarCsv([['Date', 'Title'], [$date, 'A']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'delete an old one']);

    expect(CalendarImport::query()->pending()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('only admins import and delete; members read the list and rows; another workspace\'s import is not found', function () {
    $member = workspaceMember($this->workspace);
    $import = CalendarImport::factory()->create(['workspace_id' => $this->workspace->id, 'rows_imported' => 1, 'created_by' => $this->owner->id]);
    PastPost::factory()->create(['calendar_import_id' => $import->id, 'title' => 'Dashain menu']);
    CalendarImport::factory()->pending()->create(['workspace_id' => $this->workspace->id]);
    $file = oldCalendarCsv([['Date', 'Title'], [$this->lastYear->format('Y-m-d'), 'A']]);

    $this->actingAs($member)->post(route('socialhq.brand.calendars.store'), ['file' => $file], ['Accept' => 'application/json'])->assertForbidden();
    $this->actingAs($member)->putJson(route('socialhq.brand.calendars.update', $import->id), ['sheet' => 0, 'mapping' => ['date' => 0], 'date_order' => 'day_first'])->assertForbidden();
    $this->actingAs($member)->deleteJson(route('socialhq.brand.calendars.destroy', $import->id))->assertForbidden();

    $this->actingAs($member)->get(route('socialhq.brand.index'))
        ->assertInertia(fn ($page) => $page->has('pastCalendars', 1)
            ->where('pastCalendars.0.id', $import->id)
            ->where('pastCalendars.0.created_by', $this->owner->name));
    $this->actingAs($member)->getJson(route('socialhq.brand.calendars.rows', $import->id))
        ->assertOk()
        ->assertJsonPath('rows.0.title', 'Dashain menu');

    $other = CalendarImport::factory()->create();
    $this->actingAs($this->owner)->getJson(route('socialhq.brand.calendars.rows', $other->id))->assertNotFound();
    $this->actingAs($this->owner)->deleteJson(route('socialhq.brand.calendars.destroy', $other->id))->assertNotFound();
    expect(CalendarImport::query()->whereKey($other->id)->exists())->toBeTrue();
});

test('deleting an import removes its rows, and a pending one its file', function () {
    $import = CalendarImport::factory()->create(['workspace_id' => $this->workspace->id]);
    PastPost::factory()->count(3)->create(['calendar_import_id' => $import->id]);
    $pending = CalendarImport::factory()->pending()->create(['workspace_id' => $this->workspace->id]);
    Storage::disk('local')->put($pending->path, 'Date');

    $this->actingAs($this->owner)->deleteJson(route('socialhq.brand.calendars.destroy', $import->id))->assertNoContent();
    $this->actingAs($this->owner)->deleteJson(route('socialhq.brand.calendars.destroy', $pending->id))->assertNoContent();

    expect(PastPost::query()->count())->toBe(0)
        ->and(CalendarImport::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($pending->path);
});

test('uploads never imported are deleted after a day, and a deleted brand takes its files', function () {
    $old = CalendarImport::factory()->pending()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subDay()->subMinute()]);
    $recent = CalendarImport::factory()->pending()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subHours(2)]);
    $imported = CalendarImport::factory()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subMonth()]);
    Storage::disk('local')->put($old->path, 'Date');
    Storage::disk('local')->put($recent->path, 'Date');

    $this->artisan('socialhq:calendars:prune')->assertSuccessful();

    expect(CalendarImport::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $imported->id])->sort()->values()->all());
    Storage::disk('local')->assertMissing($old->path);
    Storage::disk('local')->assertExists($recent->path);

    $kept = CalendarStorage::directory($this->workspace).'/upload.csv';
    Storage::disk('local')->put($kept, 'Date');
    $this->workspace->delete();

    Storage::disk('local')->assertMissing($kept);
});
