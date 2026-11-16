<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One sheet of an old calendar, as read: its header row and the non-empty
 * rows under it, each with its row number in the spreadsheet.
 */
final readonly class CalendarSheet
{
    private const string STORED_DATE = 'Y-m-d\\TH:i:s';

    /**
     * @param  list<string>  $header  The header cells, trimmed ('' for an empty one)
     * @param  list<array{number: int, cells: list<string|int|float|DateTimeInterface|null>}>  $rows
     */
    public function __construct(
        public int $index,
        public string $name,
        public int $headerRowNumber,
        public array $header,
        public array $rows,
    ) {}

    /**
     * The sheet as stored between the upload and the import (JSON: a date
     * cell becomes {"date": "..."}, every other value stays as it is).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'header_row' => $this->headerRowNumber,
            'header' => $this->header,
            'rows' => array_map(fn (array $row): array => [
                'number' => $row['number'],
                'cells' => array_map(fn (mixed $cell): mixed => $cell instanceof DateTimeInterface ? ['date' => $cell->format(self::STORED_DATE)] : $cell, $row['cells']),
            ], $this->rows),
        ];
    }

    /**
     * A sheet stored by toArray().
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            (int) data_get($stored, 'index'),
            (string) data_get($stored, 'name'),
            (int) data_get($stored, 'header_row'),
            array_map(strval(...), (array) data_get($stored, 'header', [])),
            array_map(fn (mixed $row): array => [
                'number' => (int) data_get($row, 'number'),
                'cells' => array_map(self::storedCell(...), array_values((array) data_get($row, 'cells', []))),
            ], array_values((array) data_get($stored, 'rows', []))),
        );
    }

    /**
     * The columns as the mapping step shows them.
     *
     * @return list<array{index: int, label: string, letter: string}>
     */
    public function columns(): array
    {
        return array_map(fn (int $index): array => [
            'index' => $index,
            'label' => $this->header[$index],
            'letter' => self::letter($index),
        ], array_keys($this->header));
    }

    /**
     * The first rows as text, one value per column.
     *
     * @return list<list<string>>
     */
    public function samples(int $count): array
    {
        return array_map(
            fn (array $row): array => array_map(
                fn (int $index): string => self::display($row['cells'][$index] ?? null),
                array_keys($this->header),
            ),
            array_slice($this->rows, 0, $count),
        );
    }

    /**
     * The values of one column, top to bottom.
     *
     * @return list<string|int|float|DateTimeInterface|null>
     */
    public function column(int $index): array
    {
        return array_map(fn (array $row): mixed => $row['cells'][$index] ?? null, $this->rows);
    }

    /**
     * A cell as text: dates as YYYY-MM-DD, whole numbers without decimals.
     */
    public static function display(string|int|float|DateTimeInterface|null $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value => (string) (int) $value,
            default => (string) $value,
        };
    }

    private static function storedCell(mixed $cell): string|int|float|DateTimeInterface|null
    {
        if (is_array($cell)) {
            return CarbonImmutable::createFromFormat('!'.self::STORED_DATE, (string) data_get($cell, 'date')) ?: null;
        }

        return is_string($cell) || is_int($cell) || is_float($cell) ? $cell : null;
    }

    /**
     * The spreadsheet letter of a column: 0 is A, 26 is AA.
     */
    public static function letter(int $index): string
    {
        $letter = '';

        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $letter = chr(65 + (($number - 1) % 26)).$letter;
        }

        return $letter;
    }
}
