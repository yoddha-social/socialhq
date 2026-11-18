<?php

declare(strict_types=1);

use App\Jobs\RssFeed\FetchRssFeed;
use App\SocialHq\Queue\CompactHorizon;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

/**
 * The supervisors Horizon would start in an environment, as it builds them.
 *
 * @return array<string, SupervisorOptions>
 */
function compactHorizonPlan(string $environment): array
{
    $plan = new ProvisioningPlan('host', config('horizon.environments'), config('horizon.defaults'));

    return collect($plan->toSupervisorOptions()[$environment])
        ->mapWithKeys(fn (SupervisorOptions $options, string $name): array => [str($name)->after('host:')->toString() => $options])
        ->all();
}

/**
 * @return list<string>
 */
function compactHorizonQueues(): array
{
    return collect(config('horizon.defaults'))
        ->flatMap(fn (array $supervisor): array => (array) data_get($supervisor, 'queue', []))
        ->all();
}

beforeEach(function () {
    config()->set('socialhq.horizon.compact', true);
});

test('three supervisors work every queue TryPost works, each queue once', function () {
    $upstreamQueues = compactHorizonQueues();

    CompactHorizon::apply(config());

    foreach (['production', 'local'] as $environment) {
        $plan = compactHorizonPlan($environment);
        $queues = collect($plan)->flatMap(fn (SupervisorOptions $options): array => explode(',', $options->queue));

        expect(array_keys($plan))->toEqualCanonicalizing(['core', 'social-publishing', 'media'])
            ->and($queues->sort()->values()->all())->toBe(collect($upstreamQueues)->sort()->values()->all())
            ->and($queues->duplicates()->all())->toBe([]);

        foreach ($plan as $options) {
            expect($options->balance)->toBe('off')
                ->and($options->minProcesses)->toBe(1)
                ->and($options->maxProcesses)->toBeGreaterThan(1);
        }
    }
});

test('each supervisor keeps the longest timeout of what it replaced, below the redis retry_after', function () {
    $upstream = config('horizon.defaults');

    CompactHorizon::apply(config());

    foreach (CompactHorizon::GROUPS as $name => $replaced) {
        $timeout = config("horizon.defaults.{$name}.timeout");

        expect($timeout)->toBe(collect($upstream)->only($replaced)->max('timeout'))
            ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'))
            ->and(config("horizon.defaults.{$name}.memory"))->toBe(collect($upstream)->only($replaced)->max('memory'));
    }

    expect(config('horizon.defaults.core.timeout'))
        ->toBeGreaterThan((new ReflectionClass(FetchRssFeed::class))->getProperty('timeout')->getDefaultValue());
});

test('broadcasts come first and posthog last in the core pool', function () {
    CompactHorizon::apply(config());

    $queues = config('horizon.defaults.core.queue');

    expect($queues[0])->toBe('broadcasts')
        ->and(end($queues))->toBe('posthog');
});

test('the most workers of each supervisor come from the config', function () {
    config()->set('socialhq.horizon.max_processes.social-publishing', 9);

    CompactHorizon::apply(config());

    expect(compactHorizonPlan('production')['social-publishing']->maxProcesses)->toBe(9)
        ->and(compactHorizonPlan('production')['media']->maxProcesses)->toBe(config('socialhq.horizon.max_processes.media'));
});

test('a supervisor TryPost adds later is kept as it is', function () {
    config()->set('horizon.defaults.newsletters', ['connection' => 'redis', 'queue' => ['newsletters'], 'balance' => 'auto', 'maxProcesses' => 2]);
    config()->set('horizon.environments.production.newsletters', ['maxProcesses' => 5]);

    CompactHorizon::apply(config());

    expect(compactHorizonPlan('production'))->toHaveKey('newsletters')
        ->and(compactHorizonPlan('production')['newsletters']->maxProcesses)->toBe(5);
});

test('switched off, TryPost\'s supervisors stay', function () {
    config()->set('socialhq.horizon.compact', false);
    $before = config('horizon');

    CompactHorizon::apply(config());

    expect(config('horizon'))->toBe($before);
});
