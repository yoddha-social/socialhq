<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Plan;

use App\Models\Workspace;
use App\SocialHq\Plan\ContentPlan;
use App\SocialHq\Plan\GenerationStatus;
use App\SocialHq\Plan\PlanStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPlan>
 */
class ContentPlanFactory extends Factory
{
    protected $model = ContentPlan::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'month' => now('UTC')->addMonthNoOverflow()->startOfMonth()->toDateString(),
            'status' => PlanStatus::Draft,
            'generation_status' => GenerationStatus::Idle,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => PlanStatus::Approved, 'approved_at' => now()]);
    }
}
