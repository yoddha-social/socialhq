<?php

declare(strict_types=1);

namespace App\SocialHq\Ai\Company;

use App\Models\Account;
use App\Models\User;
use App\SocialHq\Ai\AiRouter;
use App\SocialHq\Ai\AiTask;
use App\SocialHq\Ai\Gateway\GatewayException;
use App\SocialHq\Ai\Gateway\KeyTestResult;
use App\SocialHq\Ai\Gateway\LiteLlmAdmin;
use App\SocialHq\Companies\Company;
use App\SocialHq\Secrets\SecretBox;
use App\SocialHq\Secrets\SecretsKeyMissing;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Everything an owner does on Settings › AI. A provider key is tested
 * through LiteLLM before anything is saved, then stored only in LiteLLM (a
 * credential); the app keeps its last four characters. Each change is
 * logged in shq_company_ai_events and makes the company's next AI call
 * decide its route again.
 *
 * One change per company at a time (a cache lock), so two clicks can't
 * create two teams or two credentials.
 */
class CompanyAiManager
{
    public function __construct(private readonly LiteLlmAdmin $gateway) {}

    /**
     * Why companies can't set up AI on this server yet, as a lang key under
     * socialhq_ai.errors, or null when they can.
     */
    public function setupBlocker(): ?string
    {
        return match (true) {
            ! AiRouter::usesGateway() => 'gateway_off',
            ! SecretBox::isConfigured() => 'secrets_key_missing',
            ! $this->gateway->isConfigured() => 'gateway_not_configured',
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $options  non-secret fields, such as a Cloudflare account id
     *
     * @throws ProviderKeyRefused|GatewayException|SecretsKeyMissing
     */
    public function add(Account $account, User $user, ProviderCatalogue $provider, #[SensitiveParameter] string $key, array $options = []): ProviderCredential
    {
        return $this->locked($account, function () use ($account, $user, $provider, $key, $options): ProviderCredential {
            $this->assertCanSetUp();
            $this->assertKeyPasses($account, $user, $provider, $key, $options);

            $ai = $this->ensureGateway($account, $user);
            $name = self::credentialName((string) $ai->litellm_team_id, $provider->id);

            $this->gateway->saveCredential($name, $provider->litellmProvider(), $provider->credentialValues($key, $options));

            try {
                $credential = ProviderCredential::query()->create([
                    'account_id' => $account->id,
                    'provider' => $provider->id,
                    'litellm_credential_name' => $name,
                    'last4' => self::last4($key),
                    'options' => $options === [] ? null : $options,
                    'status' => CredentialStatus::Ok,
                    'last_tested_at' => now(),
                    'position' => (int) ProviderCredential::query()->forAccount($account)->max('position') + 1,
                    'data_confirmed_at' => $provider->confirmation() !== null ? now() : null,
                    'added_by' => $user->id,
                ]);

                $this->syncDeployments($account, $ai);
            } catch (Throwable $exception) {
                isset($credential) && $credential->delete();
                $this->removeDeployments((string) $ai->litellm_team_id, $provider->id);
                rescue(fn () => $this->gateway->deleteCredential($name), report: false);

                throw $exception;
            }

            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_ADDED, $provider->id, ['last4' => $credential->last4]);

            return $credential;
        });
    }

    /**
     * @param  array<string, string>  $options
     *
     * @throws ProviderKeyRefused|GatewayException|SecretsKeyMissing
     */
    public function replace(ProviderCredential $credential, User $user, #[SensitiveParameter] string $key, array $options = []): ProviderCredential
    {
        $account = $credential->account()->firstOrFail();

        return $this->locked($account, function () use ($credential, $account, $user, $key, $options): ProviderCredential {
            $this->assertCanSetUp();
            $provider = $this->catalogueOf($credential);
            $this->assertKeyPasses($account, $user, $provider, $key, $options);

            $this->gateway->saveCredential($credential->litellm_credential_name, $provider->litellmProvider(), $provider->credentialValues($key, $options), replace: true);

            $credential->update([
                'last4' => self::last4($key),
                'options' => $options === [] ? null : $options,
                'status' => CredentialStatus::Ok,
                'last_tested_at' => now(),
                'last_error' => null,
                'data_confirmed_at' => $provider->confirmation() !== null ? now() : $credential->data_confirmed_at,
            ]);

            // Deployments may hold a copy of the old credential: add them again.
            $this->syncDeployments($account, CompanyAi::forAccount($account));
            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_REPLACED, $credential->provider, ['last4' => $credential->last4]);

            return $credential;
        });
    }

    /**
     * Sends one short request with the stored key and records the outcome.
     *
     * @throws GatewayException
     */
    public function test(ProviderCredential $credential, User $user): KeyTestResult
    {
        $result = $this->gateway->testCredential($this->catalogueOf($credential), $credential->litellm_credential_name);

        $credential->update([
            'status' => $result->ok ? CredentialStatus::Ok : CredentialStatus::Failed,
            'last_error' => $result->errorKey,
            'last_tested_at' => now(),
        ]);

        CompanyAiEvent::record($credential->account()->firstOrFail(), $user, CompanyAiEvent::PROVIDER_TESTED, $credential->provider, ['result' => $result->errorKey ?? 'ok']);

        return $result;
    }

    /**
     * @throws GatewayException
     */
    public function remove(ProviderCredential $credential, User $user): void
    {
        $account = $credential->account()->firstOrFail();

        $this->locked($account, function () use ($credential, $account, $user): void {
            $ai = CompanyAi::forAccount($account);

            if ($ai?->litellm_team_id !== null) {
                $this->removeDeployments($ai->litellm_team_id, $credential->provider);
            }

            $this->gateway->deleteCredential($credential->litellm_credential_name);
            $credential->delete();

            $this->renumber($account);

            if ($ai !== null && ProviderCredential::query()->forAccount($account)->exists()) {
                $this->syncDeployments($account, $ai);
            }

            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_REMOVED, $credential->provider, ['last4' => $credential->last4]);
        });
    }

    /**
     * Moves a provider one place up (tried earlier) or down.
     *
     * @throws GatewayException
     */
    public function move(ProviderCredential $credential, User $user, string $direction): void
    {
        $account = $credential->account()->firstOrFail();

        $this->locked($account, function () use ($credential, $account, $user, $direction): void {
            $ordered = ProviderCredential::query()->forAccount($account)->inFallbackOrder()->get()->values();
            $index = $ordered->search(fn (ProviderCredential $item): bool => $item->is($credential));
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! $ordered->has($swapWith)) {
                return;
            }

            $other = $ordered->get($swapWith);

            DB::transaction(function () use ($ordered, $index, $swapWith): void {
                $reordered = $ordered->all();
                [$reordered[$index], $reordered[$swapWith]] = [$reordered[$swapWith], $reordered[$index]];

                foreach (array_values($reordered) as $position => $item) {
                    $item->update(['position' => $position + 1]);
                }
            });

            $this->syncDeployments($account, CompanyAi::forAccount($account));
            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_MOVED, $credential->provider, ['direction' => $direction, 'past' => $other->provider]);
        });
    }

    /**
     * @param  float|null  $monthlyUsd  null: no budget
     *
     * @throws GatewayException|SecretsKeyMissing
     */
    public function setBudget(Account $account, User $user, ?float $monthlyUsd): void
    {
        $this->locked($account, function () use ($account, $user, $monthlyUsd): void {
            $this->assertCanSetUp();
            $ai = $this->ensureGateway($account, $user);
            $previous = $ai->monthly_budget_usd;

            $this->gateway->updateTeam((string) $ai->litellm_team_id, [
                'max_budget' => $monthlyUsd,
                'budget_duration' => $ai->budget_duration,
            ]);

            $ai->update(['monthly_budget_usd' => $monthlyUsd]);

            CompanyAiEvent::record($account, $user, CompanyAiEvent::BUDGET_CHANGED, null, [
                'from' => $previous === null ? null : (string) $previous,
                'to' => $monthlyUsd === null ? null : (string) $monthlyUsd,
            ]);
        });
    }

    /**
     * The company's team and virtual key, created the first time.
     *
     * @throws GatewayException|SecretsKeyMissing
     */
    public function ensureGateway(Account $account, User $user): CompanyAi
    {
        $ai = CompanyAi::forAccount($account) ?? new CompanyAi(['account_id' => $account->id]);

        if ($ai->exists && $ai->isReady()) {
            return $ai;
        }

        $teamId = CompanyAi::teamIdFor($account);
        $alias = "socialhq-{$teamId}";
        $duration = (string) config('socialhq.company_ai.budget_duration', '30d');

        $this->gateway->saveTeam(
            $teamId,
            Company::forAccount($account)->name ?? (string) $account->name,
            CompanyRouting::groups($teamId),
            $ai->monthly_budget_usd === null ? null : (float) $ai->monthly_budget_usd,
            $duration,
        );

        // A key left by an attempt that failed half way stops working.
        $this->gateway->deleteKeyByAlias($alias);

        $ai->fill([
            'litellm_team_id' => $teamId,
            'virtual_key' => $this->gateway->generateTeamKey($teamId, $alias),
            'budget_duration' => $duration,
            'status' => CompanyAi::STATUS_ACTIVE,
        ])->save();

        CompanyAiEvent::record($account, $user, CompanyAiEvent::GATEWAY_CREATED);

        return $ai;
    }

    /**
     * Adds the company's deployments again in the current fallback order:
     * one per provider and task it serves, embeddings from the first
     * provider that has them only.
     *
     * @throws GatewayException
     */
    public function syncDeployments(Account $account, ?CompanyAi $ai): void
    {
        if ($ai === null || blank($ai->litellm_team_id)) {
            return;
        }

        $teamId = (string) $ai->litellm_team_id;
        $credentials = ProviderCredential::query()->forAccount($account)->inFallbackOrder()->get();
        $embedder = $credentials->first(fn (ProviderCredential $credential): bool => (bool) $credential->catalogue()?->serves(AiTask::Embed));

        foreach ($credentials->values() as $index => $credential) {
            $provider = $credential->catalogue();

            foreach (AiTask::cases() as $task) {
                $id = self::deploymentId($teamId, $credential->provider, $task);
                $this->gateway->deleteDeployment($id);

                if ($provider === null || ! $provider->serves($task) || ($task === AiTask::Embed && ! $credential->is($embedder))) {
                    continue;
                }

                $this->gateway->addDeployment($id, CompanyRouting::group($task, $teamId), $provider->deploymentParams($task, $credential->litellm_credential_name, $index + 1));
            }
        }

        CompanyRouting::forget($account);
    }

    public static function credentialName(string $teamId, string $provider): string
    {
        return "{$teamId}-{$provider}";
    }

    public static function deploymentId(string $teamId, string $provider, AiTask $task): string
    {
        return "{$teamId}-{$provider}-{$task->value}";
    }

    private function removeDeployments(string $teamId, string $provider): void
    {
        foreach (AiTask::cases() as $task) {
            $this->gateway->deleteDeployment(self::deploymentId($teamId, $provider, $task));
        }
    }

    /**
     * @param  array<string, string>  $options
     *
     * @throws ProviderKeyRefused
     */
    private function assertKeyPasses(Account $account, User $user, ProviderCatalogue $provider, #[SensitiveParameter] string $key, array $options): void
    {
        $result = $this->gateway->testKey($provider, $key, $options);

        if (! $result->ok) {
            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_REFUSED, $provider->id, ['reason' => $result->errorKey]);

            throw new ProviderKeyRefused((string) $result->errorKey);
        }
    }

    /**
     * @throws GatewayException|SecretsKeyMissing
     */
    private function assertCanSetUp(): void
    {
        match ($this->setupBlocker()) {
            null => null,
            'secrets_key_missing' => throw new SecretsKeyMissing,
            default => throw new GatewayException((string) $this->setupBlocker(), 'Company AI needs the LiteLLM gateway and LITELLM_ADMIN_KEY.'),
        };
    }

    private function catalogueOf(ProviderCredential $credential): ProviderCatalogue
    {
        return $credential->catalogue() ?? throw new GatewayException('provider_retired', "Provider [{$credential->provider}] is no longer in the catalogue.");
    }

    private function renumber(Account $account): void
    {
        ProviderCredential::query()->forAccount($account)->inFallbackOrder()->get()
            ->each(fn (ProviderCredential $credential, int $index) => $credential->update(['position' => $index + 1]));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function locked(Account $account, Closure $callback): mixed
    {
        try {
            return Cache::lock("socialhq:company-ai:{$account->id}", 120)->block(15, $callback);
        } finally {
            CompanyRouting::forget($account);
        }
    }

    private static function last4(#[SensitiveParameter] string $key): string
    {
        return mb_substr($key, -4);
    }
}
