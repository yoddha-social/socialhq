<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\SocialHq\Ai\AiTask;

/**
 * Where a company's AI calls go, decided once per request or job:
 * - own: its LiteLLM team key, to its own deployments (shq-<task>@<team>);
 * - platform: the platform's key (LITELLM_API_KEY), only for a company a
 *   platform admin allowed (uses_platform_ai) that has no keys of its own;
 * - none: AI fails closed and its buttons stay hidden.
 */
final readonly class CompanyRoute
{
    public const OWN = 'own';

    public const PLATFORM = 'platform';

    public const NONE = 'none';

    /**
     * @param  list<string>  $tasks  the tasks its own providers serve (own only)
     */
    private function __construct(
        public string $kind,
        public ?Account $account = null,
        public ?int $companyAiId = null,
        public ?string $teamId = null,
        public array $tasks = [],
    ) {}

    /**
     * @param  list<AiTask>  $tasks
     */
    public static function own(Account $account, CompanyAi $ai, array $tasks): self
    {
        return new self(self::OWN, $account, $ai->id, $ai->litellm_team_id, array_values(array_unique(array_map(fn (AiTask $task): string => $task->value, $tasks))));
    }

    public static function platform(?Account $account = null): self
    {
        return new self(self::PLATFORM, $account);
    }

    public static function none(?Account $account = null): self
    {
        return new self(self::NONE, $account);
    }

    public function serves(AiTask $task): bool
    {
        return $this->kind === self::OWN && in_array($task->value, $this->tasks, true);
    }
}
