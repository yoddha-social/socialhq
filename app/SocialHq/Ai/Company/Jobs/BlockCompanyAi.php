<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company\Jobs;

use App\SocialHq\Ai\Company\CompanyAiManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Blocks a paused company's team in LiteLLM, or unblocks it when the
 * company is restored. The app already refuses its AI meanwhile.
 */
class BlockCompanyAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $teamId, public bool $blocked)
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
        $manager->setBlocked($this->teamId, $this->blocked);
    }
}
