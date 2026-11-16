<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Usage;

/**
 * How an AI call ended, as Settings › AI › Usage shows it.
 */
enum AiUsageStatus: string
{
    case Ok = 'ok';

    /** The provider or LiteLLM answered with an error, or the connection failed. */
    case Failed = 'failed';

    /** HTTP 429: a provider's or LiteLLM's rate limit, or no deployment free. */
    case RateLimited = 'rate_limited';

    /** LiteLLM refused it: the company's monthly budget is spent. */
    case BlockedBudget = 'blocked_budget';

    /** The app refused it before sending: no AI provider for this company and task. */
    case BlockedNoRoute = 'blocked_no_route';

    public function isProblem(): bool
    {
        return $this !== self::Ok;
    }
}
