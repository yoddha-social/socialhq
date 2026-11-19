<?php

declare(strict_types=1);

namespace App\SocialHq\Queue;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;

/**
 * Social HQ's smaller Horizon layout (`socialhq.horizon`, see config/socialhq.php).
 *
 * TryPost's supervisors are folded into three, each a single pool
 * (`balance: off`): one worker when idle, one more per waiting job up to
 * its `maxProcesses`. Every queue TryPost works is still worked, with the
 * longest timeout and the highest memory of the supervisors it came from.
 * A supervisor that is in no group (one TryPost adds later) stays as it is.
 */
final class CompactHorizon
{
    /**
     * Compact supervisor => the TryPost supervisors it replaces.
     *
     * @var array<string, list<string>>
     */
    public const GROUPS = [
        'core' => ['supervisor-1', 'webhooks', 'analytics', 'rss-feeds'],
        'social-publishing' => ['social-publishing'],
        'media' => ['media-imports', 'media-adoption'],
    ];

    public static function apply(Repository $config): void
    {
        if (! $config->get('socialhq.horizon.compact')) {
            return;
        }

        $upstream = (array) $config->get('horizon.defaults', []);
        $replaced = Arr::flatten(self::GROUPS);

        $defaults = collect(self::GROUPS)
            ->map(fn (array $names, string $name): array => self::supervisor($name, Arr::only($upstream, $names), $config))
            ->filter(fn (array $supervisor): bool => $supervisor['queue'] !== [])
            ->merge(Arr::except($upstream, $replaced))
            ->all();

        // Horizon merges each environment over the defaults, so an override left
        // for a replaced supervisor would start it again with half its options.
        $environments = collect((array) $config->get('horizon.environments', []))
            ->map(fn (array $supervisors): array => Arr::except($supervisors, $replaced))
            ->all();

        $config->set([
            'horizon.defaults' => $defaults,
            'horizon.environments' => $environments,
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $replaced
     * @return array<string, mixed>
     */
    private static function supervisor(string $name, array $replaced, Repository $config): array
    {
        $supervisors = collect($replaced);

        // Broadcasts are what a person on the page waits for; PostHog events can wait.
        $queues = $supervisors
            ->flatMap(fn (array $supervisor): array => (array) data_get($supervisor, 'queue', []))
            ->unique()
            ->sortBy(fn (string $queue): int => match ($queue) {
                'broadcasts' => -1,
                'posthog' => 1,
                default => 0,
            })
            ->values()
            ->all();

        return [
            'connection' => data_get($supervisors->first(), 'connection', 'redis'),
            'queue' => $queues,
            'balance' => 'off',
            'minProcesses' => 1,
            'maxProcesses' => max(1, (int) $config->get("socialhq.horizon.max_processes.{$name}", 1)),
            'balanceMaxShift' => max(1, (int) $config->get('socialhq.horizon.balance_max_shift', 1)),
            'balanceCooldown' => max(1, (int) $config->get('socialhq.horizon.balance_cooldown', 3)),
            'timeout' => (int) $supervisors->max('timeout'),
            'memory' => (int) $supervisors->max('memory'),
            'tries' => (int) ($supervisors->max('tries') ?? 1),
            'maxTime' => 0,
            'maxJobs' => 0,
            'nice' => 0,
        ];
    }
}
