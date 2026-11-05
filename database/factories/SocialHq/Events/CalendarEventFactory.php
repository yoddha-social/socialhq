<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Events;

use App\Models\Account;
use App\Models\User;
use App\SocialHq\Events\CalendarEvent;
use App\SocialHq\Events\EventCategory;
use App\SocialHq\Events\EventRegion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);
        $day = now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays($this->faker->numberBetween(0, 25));

        return [
            'account_id' => Account::factory(),
            'workspace_id' => null,
            'key' => Str::slug($name),
            'name' => Str::title($name),
            'category' => EventCategory::Festival,
            'region' => EventRegion::Nepal,
            'starts_on' => $day->toDateString(),
            'ends_on' => $day->toDateString(),
            'public_holiday' => false,
            'source_url' => 'https://moha.gov.np/',
            'source_name' => 'Ministry of Home Affairs',
        ];
    }

    public function verified(?User $by = null): static
    {
        return $this->state(fn (): array => ['verified_at' => now(), 'verified_by' => $by?->id]);
    }
}
