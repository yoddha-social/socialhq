<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\SocialHq\Ai\Gateway\GatewayException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Brings every company's LiteLLM deployments in line with the provider
 * catalogue (config/socialhq.php "company_ai"), after a release changed a
 * model's settings, such as turning reasoning off. Only deployments whose
 * settings differ are removed and added again, so a run with nothing to
 * change sends nothing to LiteLLM. The entrypoint runs it on every start.
 */
class AiSyncCommand extends Command
{
    protected $signature = 'socialhq:ai-sync';

    protected $description = "Update every company's AI deployments in LiteLLM to the current provider catalogue";

    public function handle(CompanyAiManager $manager): int
    {
        $synced = 0;
        $failed = 0;

        CompanyAi::query()->whereNotNull('litellm_team_id')->with('account')->chunkById(50, function ($companies) use ($manager, &$synced, &$failed): void {
            foreach ($companies as $ai) {
                if ($ai->account === null) {
                    continue;
                }

                try {
                    $manager->resync($ai->account);
                    $synced++;
                } catch (GatewayException|LockTimeoutException $exception) {
                    $failed++;
                    $this->error("Account {$ai->account_id}: {$exception->getMessage()}");
                }
            }
        });

        $this->info("Synced {$synced} companies' AI deployments.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
