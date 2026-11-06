<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Plan;

use App\Enums\PostPlatform\ContentType;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\CopyStatus;
use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\ItemSource;
use App\SocialHq\Plan\ItemStatus;
use App\SocialHq\Plan\PlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanItem>
 */
class PlanItemFactory extends Factory
{
    protected $model = PlanItem::class;

    public function definition(): array
    {
        return [
            'plan_id' => ContentPlan::factory(),
            'workspace_id' => fn (array $attributes): string => ContentPlan::query()->findOrFail($attributes['plan_id'])->workspace_id,
            'scheduled_on' => fn (array $attributes): string => ContentPlan::query()->findOrFail($attributes['plan_id'])->month->addDays($this->faker->numberBetween(0, 27))->toDateString(),
            'platform' => 'instagram',
            'content_type' => ContentType::InstagramFeed,
            'creative' => CreativeKind::Image,
            'pillar' => null,
            'title' => $this->faker->sentence(5),
            'objective' => $this->faker->sentence(10),
            'status' => ItemStatus::Planned,
            'source' => ItemSource::Manual,
            'copy_status' => CopyStatus::None,
        ];
    }

    public function suggested(): static
    {
        return $this->state(fn (): array => ['status' => ItemStatus::Suggested, 'source' => ItemSource::Ai]);
    }
}
