<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Production;

use App\SocialHq\Plan\PlanItem;
use App\SocialHq\Production\Brief;
use App\SocialHq\Production\BriefKind;
use App\SocialHq\Production\BriefStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brief>
 */
class BriefFactory extends Factory
{
    protected $model = Brief::class;

    public function definition(): array
    {
        return [
            'item_id' => PlanItem::factory(),
            'workspace_id' => fn (array $attributes): string => PlanItem::query()->findOrFail($attributes['item_id'])->workspace_id,
            'kind' => BriefKind::Graphic,
            'stage' => BriefStage::Todo,
            'format_notes' => '1080 × 1350, 4:5',
            'direction' => $this->faker->sentence(12),
        ];
    }
}
