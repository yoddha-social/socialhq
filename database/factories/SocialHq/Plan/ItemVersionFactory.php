<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Plan;

use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemVersion;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemVersion>
 */
class ItemVersionFactory extends Factory
{
    protected $model = ItemVersion::class;

    public function definition(): array
    {
        return [
            'item_id' => PlanItem::factory(),
            'number' => fn (array $attributes): int => (int) ItemVersion::query()->where('item_id', $attributes['item_id'])->max('number') + 1,
            'caption' => $this->faker->paragraph(),
            'hashtags' => '#nepal #brand',
            'source' => ItemSource::Manual,
        ];
    }
}
