<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Throwable;

/**
 * Turns an AiTask into the ordered provider and model pairs the SDK tries,
 * failing over on rate limits, overload and spent credits.
 *
 * With the LiteLLM gateway a route is one target, the task's LiteLLM model
 * group, and LiteLLM does the free-first fallback, limits and budget. With
 * direct routing the route lists providers the app calls with its own keys.
 * Either way only approved providers are used: any other target is dropped,
 * so a key for a provider that trains on prompts can never be called.
 */
final class AiRouter
{
    /** Text and embeddings through LiteLLM (openai-compatible driver). */
    public const LITELLM = 'litellm';

    /** Images through LiteLLM (openai driver: the images endpoints). */
    public const LITELLM_IMAGES = 'litellm-images';

    /**
     * Adds the two LiteLLM provider entries to config/ai.php's providers
     * when the gateway has a key. Called once at boot.
     */
    public static function registerGateway(): void
    {
        $key = config('socialhq.ai.litellm.key');

        if (blank($key)) {
            return;
        }

        $url = rtrim((string) config('socialhq.ai.litellm.url'), '/');
        $dimensions = config('socialhq.ai.litellm.embedding_dimensions');

        config([
            'ai.providers.'.self::LITELLM => [
                'driver' => 'openai-compatible',
                'url' => $url,
                'key' => $key,
                'models' => [
                    'text' => ['default' => AiTask::Write->litellmGroup()],
                    'embeddings' => array_filter([
                        'default' => AiTask::Embed->litellmGroup(),
                        'dimensions' => filled($dimensions) ? (int) $dimensions : null,
                    ]),
                ],
            ],
            'ai.providers.'.self::LITELLM_IMAGES => [
                'driver' => 'openai',
                'url' => $url,
                'key' => $key,
                'store' => false,
                'models' => [],
            ],
        ]);
    }

    public static function usesGateway(): bool
    {
        return config('socialhq.ai.gateway') === 'litellm';
    }

    /**
     * The route the SDK should try, in order. Targets without credentials are
     * dropped while another target has them; a route with none left keeps its
     * targets so the SDK's own errors (and test fakes) still apply.
     *
     * @return array<string, string|null>
     *
     * @throws AiUnavailableException when no approved provider serves the task
     */
    public static function route(AiTask $task): array
    {
        $targets = self::approvedTargets($task);

        if ($targets === []) {
            throw new AiUnavailableException("No approved AI provider is configured for the [{$task->value}] task.");
        }

        $withCredentials = array_filter($targets, fn (?string $model, string $provider): bool => self::hasCredentials($provider), ARRAY_FILTER_USE_BOTH);

        return $withCredentials === [] ? $targets : $withCredentials;
    }

    /**
     * Whether a call for this task can be made: an approved provider that
     * supports it and has credentials.
     */
    public static function isAvailable(AiTask $task): bool
    {
        foreach (array_keys(self::approvedTargets($task)) as $provider) {
            if (self::hasCredentials($provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The driver every target of the task's route shares, for
     * provider-specific request shapes such as image aspect ratios; null when
     * the route mixes drivers (or has none), so callers pick a shape every
     * driver accepts. Read from route(), the targets the SDK really tries.
     */
    public static function sharedDriver(AiTask $task): ?string
    {
        try {
            $providers = array_keys(self::route($task));
        } catch (AiUnavailableException) {
            return null;
        }

        $drivers = array_unique(array_map(fn (string $provider): mixed => config("ai.providers.{$provider}.driver"), $providers));

        return count($drivers) === 1 && is_string($drivers[0]) ? $drivers[0] : null;
    }

    /**
     * Whether a provider entry may be called at all. ApprovedProvidersGuard
     * asks this before every SDK request, whatever code made it.
     */
    public static function isApproved(string $provider): bool
    {
        return in_array($provider, self::approvedProviders(), true);
    }

    /**
     * Every target of the task as configured, with whether it is used and,
     * when not, why. For the socialhq:ai-routes command.
     *
     * @return list<array{provider: string, model: string|null, used: bool, reason: string|null}>
     */
    public static function explain(AiTask $task): array
    {
        $rows = [];

        if (self::usesGateway() && blank(config("socialhq.ai.routes.{$task->value}")) && ! self::gatewayServes($task)) {
            return [['provider' => $task->litellmProvider(), 'model' => $task->litellmGroup(), 'used' => false, 'reason' => 'not in SOCIALHQ_AI_GATEWAY_TASKS']];
        }

        foreach (self::configuredTargets($task) as $provider => $model) {
            $reason = self::rejection($task, $provider) ?? (self::hasCredentials($provider) ? null : 'no key');
            $rows[] = ['provider' => $provider, 'model' => $model, 'used' => $reason === null, 'reason' => $reason];
        }

        return $rows;
    }

    /**
     * @return array<string, string|null>
     */
    private static function approvedTargets(AiTask $task): array
    {
        return array_filter(
            self::configuredTargets($task),
            fn (?string $model, string $provider): bool => self::rejection($task, $provider) === null,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The task's route as written: SOCIALHQ_AI_ROUTE_<TASK> when set, else the
     * gateway's model group, else the SDK's default provider.
     *
     * @return array<string, string|null>
     */
    private static function configuredTargets(AiTask $task): array
    {
        $written = config("socialhq.ai.routes.{$task->value}");

        if (filled($written)) {
            return self::parse((string) $written);
        }

        if (self::usesGateway()) {
            return self::gatewayServes($task) ? [$task->litellmProvider() => $task->litellmGroup()] : [];
        }

        $default = match ($task) {
            AiTask::Image => config('ai.default_for_images', 'gemini'),
            AiTask::Embed => config('ai.default_for_embeddings'),
            default => config('ai.default'),
        };

        return filled($default) ? [(string) $default => null] : [];
    }

    /**
     * "groq:openai/gpt-oss-120b, gemini" → ['groq' => 'openai/gpt-oss-120b', 'gemini' => null].
     * The model is everything after the first colon, so ids with colons survive.
     *
     * @return array<string, string|null>
     */
    private static function parse(string $route): array
    {
        $targets = [];

        foreach (Str::of($route)->explode(',')->map(fn (string $target): string => trim($target))->filter() as $target) {
            $provider = Str::before($target, ':');
            $model = Str::contains($target, ':') ? (trim(Str::after($target, ':')) ?: null) : null;
            $targets[$provider] ??= $model;
        }

        return $targets;
    }

    /**
     * Why a target can't serve the task, or null when it can.
     */
    /**
     * The gateway serves only the tasks listed in SOCIALHQ_AI_GATEWAY_TASKS:
     * shq-image and shq-embed have no free provider, so they are listed only
     * once the paid key is set in LiteLLM. An unlisted task is unavailable
     * and its button stays hidden instead of failing on every click.
     */
    private static function gatewayServes(AiTask $task): bool
    {
        return in_array($task->value, Arr::wrap(config('socialhq.ai.litellm.tasks', [])), true);
    }

    private static function rejection(AiTask $task, string $provider): ?string
    {
        if (! self::isApproved($provider)) {
            return 'not approved';
        }

        if (! is_array(config("ai.providers.{$provider}"))) {
            return 'not configured';
        }

        try {
            $instance = app(AiManager::class)->instance($provider);
        } catch (Throwable) {
            return 'not configured';
        }

        return $instance instanceof ($task->contract()) ? null : "no {$task->value} support";
    }

    /**
     * @return list<string>
     */
    private static function approvedProviders(): array
    {
        return Arr::wrap(config('socialhq.ai.approved_providers', []));
    }

    private static function hasCredentials(string $provider): bool
    {
        $config = config("ai.providers.{$provider}");

        if (! is_array($config)) {
            return false;
        }

        return match ($config['driver'] ?? null) {
            'ollama' => filled($config['url'] ?? null),
            'bedrock' => filled($config['key'] ?? null) || filled($config['access_key_id'] ?? null) || (bool) ($config['use_default_credential_provider'] ?? false),
            default => filled($config['key'] ?? null),
        };
    }
}
