<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Companies\Company;
use Laravel\Ai\AiManager;

/**
 * Decides, per company, whose LiteLLM key an AI call uses (CompanyRoute),
 * and registers a provider entry per company for the SDK.
 *
 * Each company gets its own entries, `litellm-co-<account>` and
 * `litellm-images-co-<account>`, holding its own virtual key, so the SDK's
 * cached provider instances can never carry one company's key into another
 * company's call, in a request or in a Horizon worker. When a company's key
 * changes, its entry is rewritten and the cached instance dropped.
 *
 * Decisions are kept for the request or job (a scoped binding, flushed
 * between jobs); changing a company's AI forgets its decision at once.
 */
final class CompanyRouting
{
    private const SEPARATOR = '-co-';

    /** @var array<string, CompanyRoute> */
    private array $routes = [];

    /** @var array<string, string> account id => decrypted virtual key */
    private array $keys = [];

    public static function current(): CompanyRoute
    {
        $tenant = AiTenant::current();

        if ($tenant === AiTenant::PLATFORM) {
            return CompanyRoute::platform();
        }

        return $tenant instanceof Account ? app(self::class)->forAccount($tenant) : CompanyRoute::none();
    }

    public static function forget(Account $account): void
    {
        $routing = app(self::class);
        unset($routing->routes[$account->id], $routing->keys[$account->id]);
    }

    /**
     * The model group of a company's deployments for one task.
     */
    public static function group(AiTask $task, string $teamId): string
    {
        return "{$task->litellmGroup()}@{$teamId}";
    }

    /**
     * @return list<string>
     */
    public static function groups(string $teamId): array
    {
        return array_map(fn (AiTask $task): string => self::group($task, $teamId), AiTask::cases());
    }

    /**
     * The SDK provider entry for this company and task, registered with the
     * company's virtual key.
     */
    public static function provider(AiTask $task, CompanyRoute $route): string
    {
        $name = $task->litellmProvider().self::SEPARATOR.$route->account?->id;
        $key = app(self::class)->keyFor($route);
        $url = rtrim((string) config('socialhq.ai.litellm.url'), '/');

        $entry = $task === AiTask::Image
            ? ['driver' => 'openai', 'url' => $url, 'key' => $key, 'store' => false, 'models' => []]
            : ['driver' => 'openai-compatible', 'url' => $url, 'key' => $key, 'models' => [
                'text' => ['default' => self::group(AiTask::Write, (string) $route->teamId)],
                'embeddings' => ['default' => self::group(AiTask::Embed, (string) $route->teamId)],
            ]];

        $entry['socialhq_company'] = $route->account?->id;

        if (config("ai.providers.{$name}") !== $entry) {
            config(["ai.providers.{$name}" => $entry]);
            app(AiManager::class)->forgetInstance($name);
        }

        return $name;
    }

    /**
     * Whether a provider entry is a company's LiteLLM entry, and which
     * platform entry (litellm or litellm-images) it stands for.
     */
    public static function gatewayEntryOf(string $provider): ?string
    {
        foreach ([AiRouter::LITELLM_IMAGES, AiRouter::LITELLM] as $base) {
            if (str_starts_with($provider, $base.self::SEPARATOR) && filled(config("ai.providers.{$provider}.socialhq_company"))) {
                return $base;
            }
        }

        return null;
    }

    public function forAccount(Account $account): CompanyRoute
    {
        return $this->routes[$account->id] ??= $this->decide($account);
    }

    private function decide(Account $account): CompanyRoute
    {
        $company = Company::forAccount($account);

        if ($company === null || ! $company->isApproved()) {
            return CompanyRoute::none($account);
        }

        $credentials = ProviderCredential::query()->forAccount($account)->get();

        if ($credentials->isNotEmpty()) {
            $ai = CompanyAi::forAccount($account);

            if ($ai === null || ! $ai->isReady()) {
                return CompanyRoute::none($account);
            }

            $tasks = $credentials->flatMap(fn (ProviderCredential $credential): array => $credential->catalogue()?->tasks() ?? [])->all();

            return CompanyRoute::own($account, $ai, $tasks);
        }

        return $company->uses_platform_ai && filled(config('socialhq.ai.litellm.key'))
            ? CompanyRoute::platform($account)
            : CompanyRoute::none($account);
    }

    private function keyFor(CompanyRoute $route): string
    {
        $accountId = (string) $route->account?->id;

        return $this->keys[$accountId] ??= (string) CompanyAi::query()->find($route->companyAiId)?->virtual_key;
    }
}
