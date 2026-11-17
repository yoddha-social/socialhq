<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company\Jobs;

use App\SocialHq\Ai\Company\CompanyAiManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a deleted company's team, key, deployments and provider keys
 * from LiteLLM. Its rows in the app are already gone with the account.
 */
class TearDownCompanyAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  list<string>  $credentialNames  the company's LiteLLM credentials (one per key)
     */
    public function __construct(public string $teamId, public array $credentialNames)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CompanyAiManager $manager): void
    {
        $manager->tearDown($this->teamId, $this->credentialNames);
    }
}
