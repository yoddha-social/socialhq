<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use DateTimeInterface;
use finfo;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use ZipArchive;

/**
 * Reads an old calendar (CSV or XLSX) with OpenSpout: every visible sheet
 * that has data, its header row (which need not be row 1: titles and empty
 * rows above it are skipped) and its rows.
 *
 * The type is checked by content, never by the file name alone.
 */
final class CalendarSpreadsheet
{
    public const string CSV = 'csv';

    public const string XLSX = 'xlsx';

    /** The header is looked for among this many first non-empty rows. */
    public const int HEADER_SEARCH_ROWS = 10;

    /** At most this many sheets with data are offered. */
    public const int MAX_SHEETS = 10;

    /** Rows read per sheet, empty ones included, before giving up on the rest. */
    private const int MAX_SCANNED_ROWS = 200_000;

    private const array CSV_DELIMITERS = [',', ';', "\t"];

    /**
     * The file's type when its content matches its extension, else null.
     */
    public static function detectType(string $path, string $extension): ?string
    {
        $extension = strtolower($extension);
        $head = (string) @file_get_contents($path, false, null, 0, 8192);

        if ($extension === self::XLSX) {
            return str_starts_with($head, "PK\x03\x04") && self::isWorkbook($path) ? self::XLSX : null;
        }

        if ($extension === self::CSV) {
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
            $isText = str_starts_with($mime, 'text/') || in_array($mime, ['application/csv', 'application/x-empty'], true);

            return $isText && ! str_contains($head, "\0") ? self::CSV : null;
        }

        return null;
    }

    /**
     * The sheets that have a header and at least one row under it.
     *
     * @return list<CalendarSheet>
     *
     * @throws CalendarFileProblem
     */
    public static function read(string $path, string $type): array
    {
        $maxRows = self::maxRows();
        $reader = self::reader($path, $type);

        try {
            $reader->open($path);
        } catch (OpenSpoutException) {
            throw new CalendarFileProblem('unreadable');
        }

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet instanceof SheetWithVisibilityInterface && ! $sheet->isVisible()) {
                    continue;
                }

                $read = self::readSheet($sheet, count($sheets), $maxRows);

                if ($read !== null) {
                    $sheets[] = $read;
                }

                if (count($sheets) >= self::MAX_SHEETS) {
                    break;
                }
            }
        } catch (OpenSpoutException) {
            throw new CalendarFileProblem('unreadable');
        } finally {
            $reader->close();
        }

        if ($sheets === []) {
            throw new CalendarFileProblem('no_rows');
        }

        return $sheets;
    }

    public static function maxRows(): int
    {
        return max(1, (int) config('socialhq.knowledge.calendars.max_rows', 5000));
    }

    /**
     * @throws CalendarFileProblem
     */
    private static function readSheet(SheetInterface $sheet, int $index, int $maxRows): ?CalendarSheet
    {
        $rows = [];
        $limit = self::HEADER_SEARCH_ROWS + $maxRows + 1;
        $scanned = 0;

        foreach ($sheet->getRowIterator() as $number => $row) {
            if (++$scanned > self::MAX_SCANNED_ROWS) {
                break;
            }

            // Cells may have holes (a column the sheet never wrote): keep each at its column.
            $cells = array_fill(0, $row->getNumCells(), null);

            foreach ($row->cells as $column => $cell) {
                $cells[$column] = self::value($cell);
            }

            if (array_filter($cells, fn (mixed $cell): bool => $cell !== null) === []) {
                continue;
            }

            $rows[] = ['number' => (int) $number, 'cells' => $cells];

            if (count($rows) >= $limit) {
                break;
            }
        }

        if ($rows === []) {
            return null;
        }

        $headerAt = self::headerIndex(array_slice($rows, 0, self::HEADER_SEARCH_ROWS));
        $header = self::header($rows[$headerAt]['cells']);
        $data = array_slice($rows, $headerAt + 1);

        if (count($data) > $maxRows) {
            throw new CalendarFileProblem('too_many_rows', ['max' => number_format($maxRows)]);
        }

        if ($data === [] || $header === []) {
            return null;
        }

        return new CalendarSheet($index, $sheet->getName(), $rows[$headerAt]['number'], $header, $data);
    }

    /**
     * Which of the first rows is the header: the one naming the most known
     * columns, else the first with two or more text cells, else the first.
     *
     * @param  list<array{number: int, cells: list<mixed>}>  $rows
     */
    private static function headerIndex(array $rows): int
    {
        $best = null;
        $bestScore = 0;

        foreach ($rows as $index => $row) {
            $score = count(array_filter($row['cells'], fn (mixed $cell): bool => is_string($cell) && ColumnMapping::fieldFor($cell) !== null));

            if ($score > $bestScore) {
                $best = $index;
                $bestScore = $score;
            }
        }

        if ($best !== null) {
            return $best;
        }

        foreach ($rows as $index => $row) {
            if (count(array_filter($row['cells'], is_string(...))) >= 2) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * The header cells as text, without the empty ones at the end.
     *
     * @param  list<mixed>  $cells
     * @return list<string>
     */
    private static function header(array $cells): array
    {
        $labels = array_map(fn (mixed $cell): string => mb_substr(CalendarSheet::display($cell), 0, 120), $cells);

        while ($labels !== [] && end($labels) === '') {
            array_pop($labels);
        }

        return array_values($labels);
    }

    private static function value(Cell $cell): string|int|float|DateTimeInterface|null
    {
        if ($cell instanceof ErrorCell) {
            return null;
        }

        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            is_string($value) => trim($value) === '' ? null : trim($value),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value), $value instanceof DateTimeInterface => $value,
            default => null,
        };
    }

    private static function reader(string $path, string $type): ReaderInterface
    {
        if ($type === self::XLSX) {
            return new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
        }

        $sample = (string) @file_get_contents($path, false, null, 0, 65536);

        return new CsvReader(new CsvOptions(
            SHOULD_PRESERVE_EMPTY_ROWS: true,
            FIELD_DELIMITER: self::delimiter($sample),
            // Excel on Windows saves CSV in Windows-1252 unless told to use UTF-8.
            ENCODING: mb_check_encoding(self::withoutCutCharacter($sample), 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        ));
    }

    /**
     * Comma, semicolon (Excel in many European locales) or tab, by what the
     * first line holds most of.
     */
    private static function delimiter(string $sample): string
    {
        $line = strtok(ltrim($sample, "\xEF\xBB\xBF\r\n"), "\r\n");
        $counts = array_map(fn (string $delimiter): int => substr_count((string) $line, $delimiter), self::CSV_DELIMITERS);
        $best = array_search(max($counts), $counts, true);

        return max($counts) > 0 ? self::CSV_DELIMITERS[$best] : ',';
    }

    /**
     * The sample without a multibyte character cut in half at its end.
     */
    private static function withoutCutCharacter(string $sample): string
    {
        return strlen($sample) < 65536 ? $sample : (string) preg_replace('/[\x80-\xFF]{1,3}$/', '', $sample);
    }

    private static function isWorkbook(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $isWorkbook = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();

        return $isWorkbook;
    }
}
