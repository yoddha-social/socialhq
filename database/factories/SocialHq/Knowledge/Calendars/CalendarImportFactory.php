<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Knowledge\Calendars;

use App\Models\Workspace;
use App\SocialHq\Knowledge\Calendars\CalendarImport;
use App\SocialHq\Knowledge\Calendars\CalendarImportStatus;
use App\SocialHq\Knowledge\Calendars\DateOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarImport>
 */
class CalendarImportFactory extends Factory
{
    protected $model = CalendarImport::class;

    public function definition(): array
    {
        $last = now()->subMonths(2)->startOfDay();

        return [
            'workspace_id' => Workspace::factory(),
            'file_name' => "{$this->faker->word()} calendar.csv",
            'file_type' => 'csv',
            'status' => CalendarImportStatus::Imported,
            'sheet' => 0,
            'column_mapping' => ['date' => 0, 'title' => 1],
            'date_order' => DateOrder::DayFirst,
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'skipped_reasons' => [],
            'first_date' => $last->subMonth()->toDateString(),
            'last_date' => $last->toDateString(),
        ];
    }

    /**
     * Uploaded but never confirmed: the file is still on the disk.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CalendarImportStatus::Pending,
            'path' => 'socialhq/calendars/pending/'.$this->faker->uuid().'.csv',
            'column_mapping' => null,
            'first_date' => null,
            'last_date' => null,
        ]);
    }
}
