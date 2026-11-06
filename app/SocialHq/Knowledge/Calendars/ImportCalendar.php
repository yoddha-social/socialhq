<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The second step of an import: read the uploaded file again with the
 * team's column mapping, store its rows as past posts in one transaction,
 * then delete the file. Rows that can't be used are skipped with a reason;
 * the first ones are kept for the page.
 */
final class ImportCalendar
{
    /** How many skipped rows are kept with their reason. */
    public const int SKIPPED_KEPT = 20;

    private const int INSERT_CHUNK = 500;

    /**
     * @param  array<string, int|null>  $mapping  Field => column index
     *
     * @throws ValidationException
     */
    public static function handle(CalendarImport $import, int $sheetIndex, array $mapping, DateOrder $order): CalendarImport
    {
        $sheet = self::sheet($import, $sheetIndex);
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $connected = self::connectedPlatforms($import->workspace_id);

        $rows = [];
        $skipped = [];
        $skippedCount = 0;
        $now = now();

        foreach ($sheet->rows as $row) {
            $parsed = self::row($row['cells'], $mapping, $order, $today, $connected);

            if ($parsed instanceof SkipReason) {
                $skippedCount++;

                if (count($skipped) < self::SKIPPED_KEPT) {
                    $skipped[] = [
                        'row' => $row['number'],
                        'reason' => $parsed->value,
                        'value' => self::text($row['cells'][$mapping[ColumnMapping::DATE]] ?? null, 40),
                    ];
                }

                continue;
            }

            $rows[] = [...$parsed, 'row_number' => $row['number'], 'workspace_id' => $import->workspace_id, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rows === []) {
            $top = collect($skipped)->countBy('reason')->sortDesc()->keys()->first() ?? SkipReason::Empty->value;

            throw ValidationException::withMessages(['rows' => __('socialhq_calendars.errors.nothing_to_import', [
                'reason' => __("socialhq_calendars.skipped.reasons.{$top}"),
            ])]);
        }

        $dates = array_column($rows, 'posted_on');

        DB::transaction(function () use ($import, $rows, $skipped, $skippedCount, $sheetIndex, $mapping, $order, $dates): void {
            $locked = CalendarImport::query()->whereKey($import->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== CalendarImportStatus::Pending) {
                throw ValidationException::withMessages(['rows' => __('socialhq_calendars.errors.already_imported')]);
            }

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                PastPost::query()->insert(array_map(fn (array $row): array => [...$row, 'calendar_import_id' => $import->id], $chunk));
            }

            CalendarStorage::forget($locked->path);

            $locked->update([
                'status' => CalendarImportStatus::Imported,
                'path' => null,
                'sheet' => $sheetIndex,
                'column_mapping' => $mapping,
                'date_order' => $order,
                'rows_imported' => count($rows),
                'rows_skipped' => $skippedCount,
                'skipped_reasons' => $skipped,
                'first_date' => min($dates),
                'last_date' => max($dates),
            ]);

            $import->setRawAttributes($locked->getAttributes(), true);
        });

        return $import;
    }

    /**
     * @throws ValidationException
     */
    private static function sheet(CalendarImport $import, int $sheetIndex): CalendarSheet
    {
        $path = $import->path;

        if ($import->status !== CalendarImportStatus::Pending || blank($path) || ! CalendarStorage::disk()->exists($path)) {
            throw ValidationException::withMessages(['rows' => __('socialhq_calendars.errors.upload_gone')]);
        }

        try {
            $sheets = CalendarSpreadsheet::read(CalendarStorage::disk()->path($path), $import->file_type);
        } catch (CalendarFileProblem $problem) {
            throw ValidationException::withMessages(['rows' => $problem->userMessage()]);
        }

        $sheet = collect($sheets)->firstWhere('index', $sheetIndex);

        if (! $sheet instanceof CalendarSheet) {
            throw ValidationException::withMessages(['sheet' => __('socialhq_calendars.errors.sheet_gone')]);
        }

        return $sheet;
    }

    /**
     * One row as past post columns, or why it was skipped.
     *
     * @param  list<mixed>  $cells
     * @param  array<string, int|null>  $mapping
     * @param  array<string, Platform>  $connected
     * @return array<string, mixed>|SkipReason
     */
    private static function row(array $cells, array $mapping, DateOrder $order, CarbonImmutable $today, array $connected): array|SkipReason
    {
        $cell = fn (string $field): mixed => $mapping[$field] === null ? null : ($cells[$mapping[$field]] ?? null);

        $channel = self::text($cell('channel'), 100);
        $format = self::text($cell('format'), 100);
        $content = [
            'channel_label' => $channel,
            'format' => $format,
            'title' => self::text($cell('title'), 255),
            'caption' => self::text($cell('caption'), 10_000),
            'pillar' => self::text($cell('pillar'), 100),
            'notes' => self::text($cell('notes'), 2_000),
            'link' => CalendarRow::link(self::text($cell('link'), 2048)),
        ];

        if (array_filter($content, filled(...)) === []) {
            return SkipReason::Empty;
        }

        $date = CalendarRow::date($cell(ColumnMapping::DATE), $order, $today);

        if ($date instanceof SkipReason) {
            return $date;
        }

        $platform = CalendarRow::platform(CalendarRow::network($channel), $channel, $connected);

        return [
            ...$content,
            'posted_on' => $date->toDateString(),
            'platform' => $platform?->value,
            'content_type' => CalendarRow::contentType($format, $platform)?->value,
            'engagement' => CalendarRow::count($cell('engagement')),
            'reach' => CalendarRow::count($cell('reach')),
            'views' => CalendarRow::count($cell('views')),
        ];
    }

    /**
     * The network => platform the workspace connected (Instagram through
     * Facebook, a LinkedIn page), so imported rows match its channels.
     *
     * @return array<string, Platform>
     */
    private static function connectedPlatforms(string $workspaceId): array
    {
        return SocialAccount::query()
            ->where('workspace_id', $workspaceId)
            ->get(['id', 'platform'])
            ->mapWithKeys(fn (SocialAccount $account): array => [$account->platform->network() => $account->platform])
            ->all();
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $text = trim(CalendarSheet::display($value));

        return $text === '' ? null : Str::limit($text, $limit - 1, '…');
    }
}
