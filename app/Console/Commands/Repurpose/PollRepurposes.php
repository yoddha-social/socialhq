<?php

declare(strict_types=1);

namespace App\Console\Commands\Repurpose;

use App\Enums\Repurpose\Status;
use App\Jobs\Repurpose\PollRepurposeSource;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\SocialHq\Companies\CompanyAccess;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class PollRepurposes extends Command
{
    protected $signature = 'repurpose:poll';

    protected $description = 'Poll due repurpose sources for videos published outside TryPost';

    public function handle(): int
    {
        $accountIds = Repurpose::query()
            ->where('status', Status::Active)
            // Social HQ: a paused company's repurposes wait until it is restored.
            ->whereNotIn('workspace_id', array_keys(CompanyAccess::pausedWorkspaceIds()))
            ->where(fn (Builder $query) => $query->whereNull('next_poll_at')->orWhere('next_poll_at', '<=', now()))
            ->distinct()
            ->pluck('source_social_account_id')
            ->filter();

        SocialAccount::query()
            ->whereKey($accountIds)
            ->chunkById(100, function ($accounts): void {
                foreach ($accounts as $account) {
                    PollRepurposeSource::dispatch($account);
                }
            });

        return self::SUCCESS;
    }
}
