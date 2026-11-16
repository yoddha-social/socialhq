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
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * Everything an owner does on Settings › AI. A provider key is tested
 * through LiteLLM before anything is saved, then stored only in LiteLLM (a
 * credential, one per key: a company may add several for one provider);
 * the app keeps its last four characters. Each change is logged in
 * shq_company_ai_events and makes the company's next AI call decide its
 * route again.
 *
 * One change per company at a time (a cache lock), so two clicks can't
 * create two teams or two credentials.
 */
class CompanyAiManager
{
    /**
     * A change may re-add every deployment (providers × tasks admin calls of
     * up to 20 s each), so the lock outlives the worst case; it is released
     * as soon as the change ends.
     */
    private const LOCK_SECONDS = 900;

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
    public function add(Account $account, User $user, ProviderCatalogue $provider, ProviderKeySettings $settings, #[SensitiveParameter] string $key, array $options = []): ProviderCredential
    {
        return $this->locked($account, function () use ($account, $user, $provider, $settings, $key, $options): ProviderCredential {
            $this->assertCanSetUp();
            $draft = new ProviderCredential(['provider' => $provider->id, ...$settings->attributes()]);
            $this->assertKeyPasses($account, $user, $provider, (string) $draft->testModel(), $key, $options);

            $ai = $this->ensureGateway($account, $user);
            $name = self::credentialName((string) $ai->litellm_team_id, $provider->id);

            $this->gateway->saveCredential($name, $provider->litellmProvider(), $provider->credentialValues($key, $options));

            try {
                $credential = ProviderCredential::query()->create([
                    ...$settings->attributes(),
                    'account_id' => $account->id,
                    'provider' => $provider->id,
                    'litellm_credential_name' => $name,
                    // A new credential name: LiteLLM holds no deployment for it yet.
                    'deployments' => [],
                    'last4' => self::last4($key),
                    'options' => $options === [] ? null : $options,
                    'status' => CredentialStatus::Ok,
                    'last_tested_at' => now(),
                    'position' => (int) ProviderCredential::query()->forAccount($account)->max('position') + 1,
                    'data_confirmed_at' => $provider->confirmation($settings->tier) !== null ? now() : null,
                    'added_by' => $user->id,
                ]);

                $this->syncDeployments($account, $ai);
            } catch (Throwable $exception) {
                if (isset($credential)) {
                    $credential->refresh();
                    rescue(fn () => $this->removeDeployments($credential), report: false);
                    $credential->delete();
                }

                rescue(fn () => $this->gateway->deleteCredential($name), report: false);
                // The failed sync may have stopped before other keys' changes: finish them.
                rescue(fn () => $this->syncDeployments($account, $ai));

                throw $exception;
            }

            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_ADDED, $provider->id, $this->details($credential));

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
            $this->assertKeyPasses($account, $user, $provider, $this->testModelOf($credential), $key, $options);

            $this->gateway->saveCredential($credential->litellm_credential_name, $provider->litellmProvider(), $provider->credentialValues($key, $options), replace: true);

            $credential->update([
                'last4' => self::last4($key),
                'options' => $options === [] ? null : $options,
                'status' => CredentialStatus::Ok,
                'last_tested_at' => now(),
                'last_error' => null,
                'data_confirmed_at' => $provider->confirmation($credential->tier) !== null ? now() : $credential->data_confirmed_at,
            ]);

            // Deployments may hold a copy of the old credential: add them again.
            $held = $credential->deployments;
            ProviderCredential::withoutTimestamps(fn (): bool => $credential->forceFill(['deployments' => $held === null ? null : array_fill_keys(array_keys($held), 'replaced')])->saveQuietly());
            $this->syncDeployments($account, CompanyAi::forAccount($account));
            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_REPLACED, $credential->provider, $this->details($credential));

            return $credential;
        });
    }

    /**
     * Renames a key, or changes its plan, tasks or models. When the model
     * a test calls changes, the stored key is tested with it first, so a
     * typo in a model id is refused instead of failing every call.
     *
     * @throws ProviderKeyRefused|GatewayException|SecretsKeyMissing
     */
    public function configure(ProviderCredential $credential, User $user, ProviderKeySettings $settings): ProviderCredential
    {
        $account = $credential->account()->firstOrFail();

        return $this->locked($account, function () use ($credential, $account, $user, $settings): ProviderCredential {
            $this->assertCanSetUp();
            $provider = $this->catalogueOf($credential);
            $draft = (clone $credential)->fill($settings->attributes());
            $testModel = $this->testModelOf($draft);

            if ($testModel !== $credential->testModel()) {
                $result = $this->gateway->testCredential($testModel, $credential->litellm_credential_name);

                if (! $result->ok) {
                    CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_REFUSED, $credential->provider, ['reason' => $result->errorKey, 'label' => $settings->label]);

                    throw new ProviderKeyRefused((string) $result->errorKey);
                }
            }

            $before = $credential->only(['label', 'tier', 'tasks', 'models']);
            $tierChanged = $credential->tier !== $settings->tier;

            $credential->update([
                ...$settings->attributes(),
                'data_confirmed_at' => $tierChanged && $provider->confirmation($settings->tier) !== null ? now() : $credential->data_confirmed_at,
            ]);

            if ($before !== $credential->only(['label', 'tier', 'tasks', 'models'])) {
                $this->syncDeployments($account, CompanyAi::forAccount($account));
            }

            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_CHANGED, $credential->provider, $this->details($credential));

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
        $this->catalogueOf($credential);
        $result = $this->gateway->testCredential($this->testModelOf($credential), $credential->litellm_credential_name);

        $credential->update([
            'status' => $result->ok ? CredentialStatus::Ok : CredentialStatus::Failed,
            'last_error' => $result->errorKey,
            'last_tested_at' => now(),
        ]);

        CompanyAiEvent::record($credential->account()->firstOrFail(), $user, CompanyAiEvent::PROVIDER_TESTED, $credential->provider, ['result' => $result->errorKey ?? 'ok', 'label' => $credential->label]);

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
            $this->removeDeployments($credential);
            $this->gateway->deleteCredential($credential->litellm_credential_name);
            $credential->delete();

            $this->renumber($account);

            if ($ai !== null && ProviderCredential::query()->forAccount($account)->exists()) {
                $this->syncDeployments($account, $ai);
            }

            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_REMOVED, $credential->provider, $this->details($credential));
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
            CompanyAiEvent::record($account, $user, CompanyAiEvent::PROVIDER_MOVED, $credential->provider, ['direction' => $direction, 'label' => $credential->label, 'past' => $other->label ?? $other->provider]);
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
     * Brings the company's deployments in line with its keys, in the current
     * fallback order: one per key and task it serves, embeddings from the
     * first key that has them only. Only deployments that differ from what
     * the key's row says LiteLLM holds are removed and added.
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
        $embedder = $credentials->first(fn (ProviderCredential $credential): bool => $credential->serves(AiTask::Embed));

        try {
            foreach ($credentials->values() as $index => $credential) {
                $provider = $credential->catalogue();
                $wanted = [];

                foreach ($provider?->tasksOfAnyTier() ?? [] as $task) {
                    $model = $credential->modelFor($task);

                    if ($model === null || ! $credential->serves($task) || ($task === AiTask::Embed && ! $credential->is($embedder))) {
                        continue;
                    }

                    $wanted[$task->value] = [
                        'group' => CompanyRouting::group($task, $teamId),
                        'params' => $provider->deploymentParams($task, $credential->tier, $model, $credential->litellm_credential_name, $index + 1),
                    ];
                }

                $this->reconcileDeployments($credential, $wanted);
            }
        } finally {
            CompanyRouting::forget($account);
        }
    }

    /**
     * Removes everything LiteLLM holds for a company that is gone: its key,
     * deployments, credentials (its provider keys) and team.
     *
     * @param  list<string>  $credentialNames
     *
     * @throws GatewayException
     */
    public function tearDown(string $teamId, array $credentialNames): void
    {
        $this->gateway->deleteKeyByAlias("socialhq-{$teamId}");

        foreach ($credentialNames as $name) {
            // The rows are gone with the account: remove every task's deployment.
            foreach (AiTask::cases() as $task) {
                $this->gateway->deleteDeployment(self::deploymentId($name, $task));
            }

            $this->gateway->deleteCredential($name);
        }

        $this->gateway->deleteTeam($teamId);
    }

    /**
     * Blocks or unblocks the company's team in LiteLLM, so a paused
     * company's key stops working there too, not only in the app.
     *
     * @throws GatewayException
     */
    public function setBlocked(string $teamId, bool $blocked): void
    {
        $this->gateway->updateTeam($teamId, ['blocked' => $blocked]);
    }

    /**
     * A new key's LiteLLM credential name. Keys added before a company could
     * have several per provider are named "<team>-<provider>" (kept on
     * their rows); new ones add a random part, so two keys never collide.
     */
    public static function credentialName(string $teamId, string $provider): string
    {
        return "{$teamId}-{$provider}-".Str::lower(Str::random(8));
    }

    public static function deploymentId(string $credentialName, AiTask $task): string
    {
        return "{$credentialName}-{$task->value}";
    }

    /**
     * Removes and adds one key's deployments so LiteLLM holds exactly the
     * wanted ones, touching only those whose fingerprint changed. The row
     * records what LiteLLM holds after each step; when that becomes unsure
     * (an add failed, or the row didn't know and the run stopped half way),
     * it records "not known", so the next run removes and adds them all.
     *
     * @param  array<string, array{group: string, params: array<string, mixed>}>  $wanted  per task value
     *
     * @throws GatewayException
     */
    private function reconcileDeployments(ProviderCredential $credential, array $wanted): void
    {
        /** @var array<string, string>|null $known */
        $known = $credential->deployments;
        $held = $known ?? [];
        $tasks = $known === null ? $this->possibleTasks($credential->catalogue()) : array_keys($known);
        $sure = $known !== null;

        try {
            foreach (array_unique([...$tasks, ...array_keys($wanted)]) as $taskValue) {
                $fingerprint = isset($wanted[$taskValue]) ? self::fingerprint($wanted[$taskValue]) : null;

                if ($known !== null && data_get($held, $taskValue) === $fingerprint) {
                    continue;
                }

                $id = self::deploymentId($credential->litellm_credential_name, AiTask::from($taskValue));

                if ($known === null || isset($held[$taskValue])) {
                    $this->gateway->deleteDeployment($id);
                    unset($held[$taskValue]);
                }

                if ($fingerprint !== null) {
                    // A failed add may still have been stored by LiteLLM.
                    $sure = false;
                    $this->gateway->addDeployment($id, $wanted[$taskValue]['group'], $wanted[$taskValue]['params']);
                    $held[$taskValue] = $fingerprint;
                    $sure = $known !== null;
                }
            }

            $sure = true;
        } finally {
            ProviderCredential::withoutTimestamps(fn (): bool => $credential->forceFill(['deployments' => $sure ? $held : null])->saveQuietly());
        }
    }

    /**
     * Removes every deployment one key may have: those its row records, or,
     * when it doesn't know, every task its provider offers.
     *
     * @throws GatewayException
     */
    private function removeDeployments(ProviderCredential $credential): void
    {
        $tasks = $credential->deployments === null ? $this->possibleTasks($credential->catalogue()) : array_keys($credential->deployments);

        foreach ($tasks as $taskValue) {
            $this->gateway->deleteDeployment(self::deploymentId($credential->litellm_credential_name, AiTask::from($taskValue)));
        }
    }

    /**
     * @return list<string> the tasks a key of this provider may have deployments for
     */
    private function possibleTasks(?ProviderCatalogue $provider): array
    {
        return array_map(fn (AiTask $task): string => $task->value, $provider?->tasksOfAnyTier() ?? AiTask::cases());
    }

    /**
     * @param  array{group: string, params: array<string, mixed>}  $deployment
     */
    private static function fingerprint(array $deployment): string
    {
        return hash('sha256', (string) json_encode($deployment));
    }

    /**
     * @param  array<string, string>  $options
     *
     * @throws ProviderKeyRefused
     */
    private function assertKeyPasses(Account $account, User $user, ProviderCatalogue $provider, string $model, #[SensitiveParameter] string $key, array $options): void
    {
        $result = $this->gateway->testKey($provider, $model, $key, $options);

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
        $catalogue = $credential->catalogue();

        if ($catalogue === null || ! $catalogue->offers($credential->tier)) {
            throw new GatewayException('provider_retired', "Provider [{$credential->provider}] or its [{$credential->tier->value}] plan is no longer in the catalogue.");
        }

        return $catalogue;
    }

    private function testModelOf(ProviderCredential $credential): string
    {
        return $credential->testModel() ?? throw new GatewayException('provider_retired', "Provider [{$credential->provider}] has no model to test.");
    }

    /**
     * What an event keeps about a key: never the key, only how to tell it apart.
     *
     * @return array<string, string|null>
     */
    private function details(ProviderCredential $credential): array
    {
        return ['label' => $credential->label, 'tier' => $credential->tier->value, 'last4' => $credential->last4];
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
            return Cache::lock("socialhq:company-ai:{$account->id}", self::LOCK_SECONDS)->block(15, $callback);
        } finally {
            CompanyRouting::forget($account);
        }
    }

    private static function last4(#[SensitiveParameter] string $key): string
    {
        return mb_substr($key, -4);
    }
}
