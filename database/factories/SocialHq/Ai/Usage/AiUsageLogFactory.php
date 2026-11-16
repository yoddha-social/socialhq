<?php

declare(strict_types=1);

namespace Database\Factories\SocialHq\Ai\Usage;

use App\Models\Account;
use App\SocialHq\Ai\Usage\AiUsageLog;
use App\SocialHq\Ai\Usage\AiUsageStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiUsageLog>
 */
class AiUsageLogFactory extends Factory
{
    protected $model = AiUsageLog::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'feature' => 'post_writing_assistant',
            'task' => 'write',
            'route' => 'own',
            'status' => AiUsageStatus::Ok,
            'litellm_call_id' => (string) Str::uuid(),
            'model_group_requested' => 'shq-write',
            'model_group' => 'shq-write',
            'provider' => 'groq',
            'model' => 'llama-3.3-70b-versatile',
            'requests' => 1,
            'cost' => 0,
            'prompt_tokens' => 120,
            'completion_tokens' => 40,
            'duration_ms' => 900,
        ];
    }

    public function paid(float $cost = 0.0012): static
    {
        return $this->state(['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'model_group' => 'shq-write-paid', 'fallbacks' => 1, 'cost' => $cost]);
    }

    public function status(AiUsageStatus $status): static
    {
        return $this->state(['status' => $status, 'cost' => null, 'prompt_tokens' => null, 'completion_tokens' => null]);
    }
}
