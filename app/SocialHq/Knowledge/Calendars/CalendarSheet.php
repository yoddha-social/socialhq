<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Calendars;

use DateTimeInterface;

/**
 * One sheet of an old calendar, as read: its header row and the non-empty
 * rows under it, each with its row number in the spreadsheet.
 */
final readonly class CalendarSheet
{
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
