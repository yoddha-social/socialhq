<?php

declare(strict_types=1);

namespace App\SocialHq\Channels;

use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Deletes the Insights history of accounts no channel of their workspace is
 * connected to any more: what disconnects left behind before a disconnect
 * deleted it too (`ChannelInsights`). Run it once with --dry-run first; it
 * changes nothing then.
 */
class PruneGoneChannelInsightsCommand extends Command
{
    /**
     * The tables that hold Insights history by identity. Publication daily
     * snapshots go with their publications.
     *
     * @var list<class-string<Model>>
     */
    public const array TABLES = [
        AnalyticsPublication::class,
        AnalyticsAccountDailySnapshot::class,
        AnalyticsSyncState::class,
    ];

    protected $signature = 'socialhq:insights:prune {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete the Insights history of channels that were disconnected';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $connected = $this->connectedIdentities();
        $rows = [];

        foreach (self::TABLES as $model) {
            $gone = $this->identities($model)->reject(fn (array $identity): bool => $connected->has($this->key($identity)));
            $count = 0;

            foreach ($gone as $identity) {
                $count += $dryRun
                    ? $model::query()->where($identity)->count()
                    : $model::query()->where($identity)->delete();
            }

            $rows[] = [class_basename($model), $gone->count(), $count];
        }

        $this->table(['Table', 'Accounts gone', $dryRun ? 'Rows to delete' : 'Rows deleted'], $rows);

        return self::SUCCESS;
    }

    /**
     * @return Collection<string, true>
     */
    private function connectedIdentities(): Collection
    {
        return SocialAccount::query()
            ->select(['id', 'workspace_id', 'platform', 'platform_user_id'])
            ->lazyById()
            ->mapWithKeys(fn (SocialAccount $account): array => [$this->key(AnalyticsSyncState::providerIdentityFor($account)) => true])
            ->collect();
    }

    /**
     * @param  class-string<Model>  $model
     * @return Collection<int, array{workspace_id: string, network: string, platform_user_id: string}>
     */
    private function identities(string $model): Collection
    {
        return $model::query()
            ->toBase()
            ->whereNotNull(['workspace_id', 'network', 'platform_user_id'])
            ->distinct()
            ->get(['workspace_id', 'network', 'platform_user_id'])
            ->map(fn (object $row): array => [
                'workspace_id' => (string) $row->workspace_id,
                'network' => (string) $row->network,
                'platform_user_id' => (string) $row->platform_user_id,
            ]);
    }

    /**
     * @param  array{workspace_id: string, network: string, platform_user_id: string}  $identity
     */
    private function key(array $identity): string
    {
        return implode('|', [data_get($identity, 'workspace_id'), data_get($identity, 'network'), data_get($identity, 'platform_user_id')]);
    }
}
