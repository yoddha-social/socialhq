<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Sources;

use App\SocialHq\Knowledge\Documents\DocumentFailure;
use App\SocialHq\Knowledge\Documents\DocumentUnreadable;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use ZipArchive;

/**
 * The text of a Google Sheets workbook (exported as XLSX): each visible
 * sheet under its name, one "cell | cell" line per row, empty rows left
 * out. The zip's sizes are checked before it is read, and rows stop at
 * the calendar import's row limit.
 */
final class SheetText
{
    /**
     * @throws DocumentUnreadable
     */
    public static function fromXlsx(string $path): string
    {
        self::assertReasonableZip($path);

        $maxRows = max(1, (int) config('socialhq.knowledge.calendars.max_rows', 5000));
        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false));
        $blocks = [];
        $rows = 0;

        try {
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet instanceof SheetWithVisibilityInterface && ! $sheet->isVisible()) {
                    continue;
                }

                $lines = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_values(array_filter(array_map(self::value(...), $row->cells), fn (string $cell): bool => $cell !== ''));

                    if ($cells !== []) {
                        $lines[] = implode(' | ', $cells);
                    }

                    if (++$rows >= $maxRows) {
                        break;
                    }
                }

                if ($lines !== []) {
                    $blocks[] = "Sheet: {$sheet->getName()}\n".implode("\n", $lines);
                }

                if ($rows >= $maxRows) {
                    break;
                }
            }
        } catch (Throwable) {
            throw DocumentFailure::Unreadable->exception();
        } finally {
            $reader->close();
        }

        return implode("\n\n", $blocks);
    }

    private static function value(mixed $cell): string
    {
        if ($cell instanceof ErrorCell) {
            return '';
        }

        $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

        return match (true) {
            is_string($value) => trim((string) preg_replace('/\s+/u', ' ', $value)),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i'),
            default => '',
        };
    }

    /**
     * Refuses a workbook whose parts add up to more than documents may expand to.
     *
     * @throws DocumentUnreadable
     */
    private static function assertReasonableZip(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw DocumentFailure::Unreadable->exception();
        }

        try {
            $total = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $total += (int) data_get($zip->statIndex($index) ?: [], 'size', 0);
            }

            if ($zip->numFiles > 10000 || $total > (int) config('socialhq.knowledge.documents.max_expanded_bytes', 100 * 1024 * 1024)) {
                throw DocumentFailure::TooBig->exception();
            }
        } finally {
            $zip->close();
        }
    }
}
