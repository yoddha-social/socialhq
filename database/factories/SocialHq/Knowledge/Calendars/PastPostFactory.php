<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Knowledge\Calendars;

use App\Enums\SocialAccount\Platform;
use App\SocialHq\Knowledge\Calendars\CalendarImport;
use App\SocialHq\Knowledge\Calendars\PastPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PastPost>
 */
class PastPostFactory extends Factory
{
    protected $model = PastPost::class;

    public function definition(): array
    {
        return [
            'calendar_import_id' => CalendarImport::factory(),
            'workspace_id' => fn (array $attributes): string => CalendarImport::query()->findOrFail($attributes['calendar_import_id'])->workspace_id,
            'row_number' => $this->faker->numberBetween(2, 500),
            'posted_on' => now()->subDays($this->faker->numberBetween(30, 700))->toDateString(),
            'platform' => Platform::Instagram,
            'channel_label' => 'Instagram',
            'format' => 'Reel',
            'title' => $this->faker->sentence(5),
            'caption' => $this->faker->paragraph(),
            'pillar' => null,
        ];
    }
}
