<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\AiTenant;
use App\SocialHq\Companies\Company;
use App\SocialHq\Secrets\SecretsKeyMissing;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;
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

            // Read the key now, from the row already loaded: one that can't be
            // decrypted means no route, never an error on every page.
            $key = self::decryptedKey($ai);

            if ($key === null) {
                return CompanyRoute::none($account);
            }

            $this->keys[$account->id] = $key;
            $tasks = $credentials->flatMap(fn (ProviderCredential $credential): array => $credential->servedTasks())->all();

            return CompanyRoute::own($account, $ai, $tasks);
        }

        return self::mayUsePlatformAi($company) && filled(config('socialhq.ai.litellm.key'))
            ? CompanyRoute::platform($account)
            : CompanyRoute::none($account);
    }

    /**
     * Whether a platform admin allowed the company the platform's own AI
     * (LITELLM_API_KEY through the gateway, the provider keys in direct mode).
     */
    public static function mayUsePlatformAi(?Company $company): bool
    {
        return $company !== null && $company->isApproved() && $company->uses_platform_ai;
    }

    /**
     * The company's virtual key, or null when SOCIALHQ_SECRETS_KEY is missing
     * or no current or previous key opens it (rotated without
     * SOCIALHQ_SECRETS_PREVIOUS_KEYS; `socialhq:secrets-reencrypt` fixes
     * that). Logged once per request or job: the decision is kept.
     */
    private static function decryptedKey(CompanyAi $ai): ?string
    {
        try {
            return filled($key = $ai->virtual_key) ? $key : null;
        } catch (SecretsKeyMissing|DecryptException $exception) {
            Log::warning('Social HQ: a company\'s AI key can\'t be decrypted, so its AI is off.', [
                'account_id' => $ai->account_id,
                'reason' => $exception instanceof SecretsKeyMissing ? 'SOCIALHQ_SECRETS_KEY is not set' : 'no current or previous SOCIALHQ_SECRETS_KEY opens it',
            ]);

            return null;
        }
    }

    /**
     * decide() already read it for an own route; the lookup is a fallback.
     */
    private function keyFor(CompanyRoute $route): string
    {
        $accountId = (string) $route->account?->id;

        if (! isset($this->keys[$accountId])) {
            $ai = CompanyAi::query()->find($route->companyAiId);
            $this->keys[$accountId] = (string) ($ai === null ? null : self::decryptedKey($ai));
        }

        return $this->keys[$accountId];
    }
}
